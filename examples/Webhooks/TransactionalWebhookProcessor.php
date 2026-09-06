<?php

namespace Vladchornyi\Mono\Examples\Webhooks;

use PDO;
use PDOException;
use Throwable;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Webhooks\WebhookPayload;

/**
 * Reference webhook handler: event deduplication, entity locking, the
 * modifiedDate check, the state change, and the outbox job are committed in
 * ONE database transaction. A crash anywhere before commit leaves no trace,
 * so the redelivery is processed normally; a concurrent delivery either waits
 * for the row lock or is told to retry.
 *
 * Side effects (e-mails, ERP calls, fulfilment) are never executed here. They
 * are persisted as outbox jobs and executed by OutboxWorker after commit.
 *
 * Works with SQLite, MySQL/MariaDB, and PostgreSQL through PDO. Use the matching
 * schema.sql, schema.mysql.sql, or schema.pgsql.sql next to this file.
 */
final class TransactionalWebhookProcessor
{
    /** State changed and an outbox job was queued. Respond 200. */
    public const OUTCOME_APPLIED = 'applied';
    /** The same event was already committed. Respond 200, do nothing. */
    public const OUTCOME_DUPLICATE = 'duplicate';
    /** A newer event for this entity is already stored. Respond 200, do nothing. */
    public const OUTCOME_STALE = 'stale';
    /** Lock contention with a concurrent delivery. Respond 503 so Monobank retries. */
    public const OUTCOME_RETRY = 'retry';

    private PDO $pdo;
    private WebhookService $webhooks;
    private ?string $trustedPublicKey;
    /** @var (callable(PDO, WebhookPayload): void)|null */
    private $beforeCommit;
    private string $driver;
    private bool $inTransaction = false;

    /**
     * @param callable(PDO, WebhookPayload): void|null $beforeCommit Test hook executed inside the
     *                                                                transaction right before commit.
     */
    public function __construct(
        PDO $pdo,
        WebhookService $webhooks,
        ?string $trustedPublicKey = null,
        ?callable $beforeCommit = null
    ) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo = $pdo;
        $this->webhooks = $webhooks;
        $this->trustedPublicKey = $trustedPublicKey;
        $this->beforeCommit = $beforeCommit;
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * @throws \Vladchornyi\Mono\Exceptions\MonoWebhookException when the signature or payload is invalid (respond 400)
     * @throws Throwable on unexpected storage failures (respond 500; Monobank retries)
     */
    public function handle(string $rawBody, ?string $xSign): string
    {
        // Verification is CPU-only and happens outside the transaction.
        $event = $this->webhooks->parseVerifiedPayload($rawBody, $xSign, $this->trustedPublicKey);

        try {
            $this->begin();
        } catch (PDOException $exception) {
            if ($this->isLockContention($exception)) {
                return self::OUTCOME_RETRY;
            }

            throw $exception;
        }

        try {
            $outcome = $this->process($event, $rawBody);

            if ($outcome === self::OUTCOME_APPLIED && $this->beforeCommit !== null) {
                ($this->beforeCommit)($this->pdo, $event);
            }

            $this->commit();

            return $outcome;
        } catch (Throwable $exception) {
            $this->rollBack();

            if ($exception instanceof PDOException && $this->isLockContention($exception)) {
                return self::OUTCOME_RETRY;
            }

            throw $exception;
        }
    }

    private function process(WebhookPayload $event, string $rawBody): string
    {
        $entityKey = $event->entityKey();
        $deduplicationKey = $event->deduplicationKey();

        // 1. Claim the event. The primary key makes a committed duplicate impossible;
        //    an uncommitted duplicate from a concurrent delivery blocks or fails here.
        if (!$this->claimEvent($deduplicationKey, $entityKey)) {
            return self::OUTCOME_DUPLICATE;
        }

        // 2. Lock the entity row so two deliveries for the same invoice serialize.
        $entity = $this->lockEntity($entityKey);

        // 3. Compare dates while holding the lock. A stale event keeps its claim so
        //    its retries are cheap duplicates, but changes nothing.
        //    modified_at holds the canonical nanosecond RFC3339 string, so two events
        //    closer than a microsecond are still ordered correctly.
        if ($entity !== null && $entity['modified_at'] !== null) {
            if (!$this->webhooks->shouldApplyPayload($event, $entity['modified_at'])) {
                return self::OUTCOME_STALE;
            }
        }

        // 4. State change and outbox job in the same transaction.
        $this->upsertEntity($entityKey, $event, $rawBody, $entity !== null);
        $this->queueJob($deduplicationKey, $entityKey, $event);

        return self::OUTCOME_APPLIED;
    }

    private function begin(): void
    {
        if ($this->driver === 'sqlite') {
            // Take the write lock up front; SELECT ... FOR UPDATE does not exist in SQLite.
            // PDO does not track transactions started with exec(), hence the own flag.
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }

        $this->inTransaction = true;
    }

    private function commit(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }

        $this->inTransaction = false;
    }

    private function rollBack(): void
    {
        if (!$this->inTransaction) {
            return;
        }

        $this->inTransaction = false;

        try {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (PDOException $exception) {
            // The connection is unusable anyway; the caller's exception is more useful.
        }
    }

    private function claimEvent(string $deduplicationKey, string $entityKey): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO webhook_events (dedup_key, entity_key, received_at) VALUES (:key, :entity, :now)'
            );
            $statement->execute([
                ':key' => $deduplicationKey,
                ':entity' => $entityKey,
                ':now' => $this->now(),
            ]);

            return true;
        } catch (PDOException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @return array{entity_key: string, status: string|null, modified_at: string|null}|null
     */
    private function lockEntity(string $entityKey): ?array
    {
        $sql = 'SELECT entity_key, status, modified_at FROM webhook_entities WHERE entity_key = :key';

        if ($this->driver !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([':key' => $entityKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'entity_key' => (string) $row['entity_key'],
            'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
            'modified_at' => is_string($row['modified_at'] ?? null) ? $row['modified_at'] : null,
        ];
    }

    /**
     * Only fields present in the event are written. A payload without `status`
     * or `modifiedDate` (for example a card-tokenization update, which carries
     * `walletData` only) must never erase the last known payment status or the
     * timestamp that protects against stale deliveries. Tokenization state
     * lives in its own columns so it never competes with the payment status.
     */
    private function upsertEntity(string $entityKey, WebhookPayload $event, string $rawBody, bool $exists): void
    {
        $columns = [
            'last_payload' => json_encode($event->safeArray(), JSON_UNESCAPED_UNICODE),
            'updated_at' => $this->now(),
        ];

        if ($event->status() !== null) {
            $columns['status'] = $event->status();
        }

        if ($event->modifiedInstant() !== null) {
            $columns['modified_at'] = $event->modifiedInstant()->toRfc3339();
        }

        $walletData = $event->walletData();

        if (is_string($walletData['status'] ?? null)) {
            $columns['wallet_status'] = $walletData['status'];
            $columns['wallet_id'] = is_string($walletData['walletId'] ?? null) ? $walletData['walletId'] : null;
        }

        $parameters = [':entity_key' => $entityKey];

        foreach ($columns as $column => $value) {
            $parameters[':' . $column] = $value;
        }

        if ($exists) {
            $assignments = implode(', ', array_map(
                static fn (string $column): string => "{$column} = :{$column}",
                array_keys($columns)
            ));
            $sql = "UPDATE webhook_entities SET {$assignments} WHERE entity_key = :entity_key";
        } else {
            $names = implode(', ', array_merge(['entity_key'], array_keys($columns)));
            $placeholders = implode(', ', array_map(
                static fn (string $column): string => ":{$column}",
                array_merge(['entity_key'], array_keys($columns))
            ));
            $sql = "INSERT INTO webhook_entities ({$names}) VALUES ({$placeholders})";
        }

        $this->pdo->prepare($sql)->execute($parameters);
    }

    private function queueJob(string $deduplicationKey, string $entityKey, WebhookPayload $event): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_outbox (idempotency_key, entity_key, job_type, payload, created_at) '
            . 'VALUES (:key, :entity, :type, :payload, :now)'
        );
        $walletData = $event->walletData();
        $walletStatus = is_string($walletData['status'] ?? null) ? $walletData['status'] : null;

        // Payment events are typed by payment status; tokenization-only events by wallet status.
        $jobType = $event->status() !== null
            ? $event->type() . '.' . $event->status()
            : $event->type() . '.wallet.' . ($walletStatus ?? 'unknown');

        $statement->execute([
            ':key' => $deduplicationKey,
            ':entity' => $entityKey,
            ':type' => $jobType,
            ':payload' => json_encode([
                'entityKey' => $entityKey,
                'invoiceId' => $event->invoiceId(),
                'subscriptionId' => $event->subscriptionId(),
                'status' => $event->status(),
                'amount' => $event->amount(),
                'finalAmount' => $event->finalAmount(),
                'ccy' => $event->ccy(),
                'modifiedDate' => $event->modifiedInstant()?->toRfc3339(),
                'walletData' => $walletData === [] ? null : $walletData,
            ], JSON_UNESCAPED_UNICODE),
            ':now' => $this->now(),
        ]);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return str_starts_with($sqlState, '23');
    }

    private function isLockContention(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $driverCode === 5 && $this->driver === 'sqlite'          // SQLITE_BUSY
            || $driverCode === 6 && $this->driver === 'sqlite'          // SQLITE_LOCKED
            || in_array($driverCode, [1205, 1213], true)                 // MySQL lock wait / deadlock
            || in_array($sqlState, ['40001', '40P01', '55P03'], true)    // serialization/deadlock/lock timeout
            || str_contains($exception->getMessage(), 'database is locked');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_RFC3339_EXTENDED);
    }
}
