<?php

namespace Vladchornyi\Mono\Examples\Webhooks;

use PDO;
use Throwable;

/**
 * Executes outbox jobs written by TransactionalWebhookProcessor.
 *
 * Guarantees:
 *
 * - Jobs of one entity (invoice/subscription) run strictly in insertion order,
 *   across any number of workers: a job is leased only when no earlier
 *   unfinished job exists for the same `entity_key`. A `reversed` can never
 *   run before the `success` that precedes it, even if another worker is still
 *   executing that `success`.
 * - Every lease carries an owner token and is timed from the moment it is
 *   taken. Only the owner can mark the job done or record its failure; a
 *   worker loses that right when another worker takes over its expired lease.
 * - Delivery is at-least-once. Dispatchers must be idempotent and should pass
 *   `idempotency_key` (the webhook deduplication key) to external systems.
 *
 * A job that exhausted `maxAttempts` keeps blocking later jobs of its entity
 * on purpose: skipping it would execute effects out of order. Find such jobs
 * with `WHERE done_at IS NULL AND attempts >= :max` and resolve them manually.
 */
final class OutboxWorker
{
    private PDO $pdo;
    /** @var callable(array<string, mixed>): void */
    private $dispatcher;
    private int $leaseSeconds;
    private int $maxAttempts;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable(array<string, mixed>): void $dispatcher Receives
     *        ['id', 'idempotency_key', 'entity_key', 'job_type', 'payload' => array, 'attempts'].
     * @param callable(): int|null $clock Unix-time source, injectable for tests.
     */
    public function __construct(
        PDO $pdo,
        callable $dispatcher,
        int $leaseSeconds = 60,
        int $maxAttempts = 10,
        ?callable $clock = null
    ) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo = $pdo;
        $this->dispatcher = $dispatcher;
        $this->leaseSeconds = $leaseSeconds;
        $this->maxAttempts = $maxAttempts;
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Processes up to $limit due jobs and returns how many this worker completed.
     */
    public function runOnce(int $limit = 50): int
    {
        $completed = 0;
        $remaining = max(1, $limit);
        $attemptedIds = [];

        // Refill after each batch: completing an entity's head makes its next
        // job eligible. Count candidates too, so contention cannot cause a spin.
        while ($remaining > 0) {
            $candidates = $this->dueJobs($remaining, $attemptedIds);

            if ($candidates === []) {
                break;
            }

            foreach ($candidates as $candidate) {
                --$remaining;
                $attemptedIds[] = (int) $candidate['id'];
                $owner = $this->lease((int) $candidate['id']);

                if ($owner === null) {
                    continue; // taken by another worker, or an earlier job is unfinished
                }

                $job = $this->reload((int) $candidate['id']);

                if ($job === null) {
                    continue;
                }

                try {
                    ($this->dispatcher)([
                        'id' => (int) $job['id'],
                        'idempotency_key' => $job['idempotency_key'],
                        'entity_key' => $job['entity_key'],
                        'job_type' => $job['job_type'],
                        'payload' => (array) json_decode((string) $job['payload'], true),
                        'attempts' => (int) $job['attempts'],
                    ]);
                } catch (Throwable $exception) {
                    $this->recordFailure((int) $job['id'], $owner, $exception);
                    continue; // stays leased until leased_until, then retries in order
                }

                if ($this->markDone((int) $job['id'], $owner)) {
                    ++$completed;
                }
                // Otherwise another worker took over the expired lease.
            }
        }

        return $completed;
    }

    /**
     * @param int[] $attemptedIds Each job gets at most one attempt per run, even if its lease expires.
     * @return array<int, array<string, mixed>>
     */
    private function dueJobs(int $limit, array $attemptedIds): array
    {
        $statement = $this->pdo->prepare(
            'SELECT job.id FROM webhook_outbox job '
            . 'WHERE job.done_at IS NULL AND job.attempts < :max '
            . 'AND (job.leased_until IS NULL OR job.leased_until <= :now) '
            . 'AND NOT EXISTS (SELECT 1 FROM webhook_outbox earlier '
            . 'WHERE earlier.entity_key = job.entity_key AND earlier.id < job.id AND earlier.done_at IS NULL) '
            . ($attemptedIds !== [] ? 'AND job.id NOT IN (' . implode(',', $attemptedIds) . ') ' : '')
            . 'ORDER BY job.id LIMIT ' . max(1, $limit)
        );
        $statement->execute([':max' => $this->maxAttempts, ':now' => ($this->clock)()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically takes the job for this worker. Returns the owner token, or
     * null when the job is not leasable: done, still leased, over the attempt
     * limit, or preceded by an unfinished job of the same entity.
     */
    private function lease(int $id): ?string
    {
        $now = ($this->clock)();   // per lease, not per batch: a slow job must not shorten the next lease
        $owner = bin2hex(random_bytes(16));

        $sql = 'UPDATE webhook_outbox SET leased_until = :until, lease_owner = :owner, attempts = attempts + 1 '
            . 'WHERE id = :id AND done_at IS NULL AND attempts < :max '
            . 'AND (leased_until IS NULL OR leased_until <= :now) '
            . 'AND NOT EXISTS ('
            . '    SELECT 1 FROM webhook_outbox earlier '
            . '    WHERE earlier.entity_key = webhook_outbox.entity_key '
            . '    AND earlier.id < webhook_outbox.id AND earlier.done_at IS NULL'
            . ')';

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // MySQL forbids reading the UPDATE target in a direct subquery.
            // A self-join keeps the predecessor guard inside the atomic UPDATE.
            $sql = 'UPDATE webhook_outbox job LEFT JOIN webhook_outbox earlier '
                . 'ON earlier.entity_key = job.entity_key AND earlier.id < job.id AND earlier.done_at IS NULL '
                . 'SET job.leased_until = :until, job.lease_owner = :owner, job.attempts = job.attempts + 1 '
                . 'WHERE job.id = :id AND job.done_at IS NULL AND job.attempts < :max '
                . 'AND (job.leased_until IS NULL OR job.leased_until <= :now) AND earlier.id IS NULL';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':until' => $now + $this->leaseSeconds,
            ':owner' => $owner,
            ':id' => $id,
            ':max' => $this->maxAttempts,
            ':now' => $now,
        ]);

        return $statement->rowCount() === 1 ? $owner : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reload(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, idempotency_key, entity_key, job_type, payload, attempts FROM webhook_outbox WHERE id = :id'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function markDone(int $id, string $owner): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_outbox SET done_at = :now, last_error = NULL '
            . 'WHERE id = :id AND lease_owner = :owner AND done_at IS NULL'
        );
        $statement->execute([
            ':now' => (new \DateTimeImmutable('@' . ($this->clock)()))->format(DATE_RFC3339_EXTENDED),
            ':id' => $id,
            ':owner' => $owner,
        ]);

        return $statement->rowCount() === 1;
    }

    private function recordFailure(int $id, string $owner, Throwable $exception): void
    {
        $error = substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 1000);

        $this->pdo->prepare(
            'UPDATE webhook_outbox SET last_error = :error WHERE id = :id AND lease_owner = :owner'
        )->execute([':error' => $error, ':id' => $id, ':owner' => $owner]);
    }
}
