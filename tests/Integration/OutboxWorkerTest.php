<?php

namespace Vladchornyi\Mono\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vladchornyi\Mono\Examples\Webhooks\OutboxWorker;
use Vladchornyi\Mono\Examples\Webhooks\TransactionalWebhookProcessor;
use Vladchornyi\Mono\Services\WebhookService;

/**
 * Ordering and lease guarantees of the reference outbox worker with two
 * workers on separate connections and an injectable clock. Defaults to SQLite;
 * CI also runs this file against disposable MySQL/MariaDB/PostgreSQL databases.
 */
final class OutboxWorkerTest extends TestCase
{
    private string $databasePath = '';
    private ?string $externalDsn = null;
    private bool $tablesReady = false;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->externalDsn = getenv('MONO_WEBHOOK_TEST_DSN') ?: null;
        if ($this->externalDsn === null && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        if ($this->externalDsn === null) {
            $this->databasePath = (string) tempnam(sys_get_temp_dir(), 'mono-outbox-');
        } else {
            // These tests clear their three tables. Never point them at an application database.
            self::assertMatchesRegularExpression('/(?:^|;)dbname=mono_test(?:;|$)/', $this->externalDsn);
        }
        $pdo = $this->connect();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $file = $driver === 'sqlite' ? 'schema.sql' : 'schema.' . $driver . '.sql';
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/Webhooks/' . $file);
        $pdo->exec($schema);
        $pdo->exec($schema); // applying the schema twice must be harmless, including indexes
        $this->clearTables($pdo);
        $this->tablesReady = true;
    }

    protected function tearDown(): void
    {
        if ($this->externalDsn !== null && $this->tablesReady) {
            $this->clearTables($this->connect());
        } elseif ($this->databasePath !== '') {
            @unlink($this->databasePath);
        }
    }

    public function testJobsOfOneEntityRunInOrderEvenWhenAnotherWorkerIsExecutingTheEarlierOne(): void
    {
        $this->queue('evt-success', 'invoice:p2_1', 'invoice.success');
        $this->queue('evt-reversed', 'invoice:p2_1', 'invoice.reversed');
        $this->queue('evt-other', 'invoice:p2_2', 'invoice.success');
        $effects = [];

        $workerB = $this->worker(function (array $job) use (&$effects): void {
            $effects[] = 'B:' . $job['job_type'];
        });
        $workerA = $this->worker(function (array $job) use (&$effects, $workerB): void {
            $effects[] = 'A:' . $job['job_type'];

            if ($job['job_type'] === 'invoice.success' && $job['entity_key'] === 'invoice:p2_1') {
                // While A is still inside `success`, B polls the outbox.
                self::assertSame(1, $workerB->runOnce(), 'B may take the unrelated entity only');
            }
        });

        self::assertSame(2, $workerA->runOnce());
        self::assertSame(['A:invoice.success', 'B:invoice.success', 'A:invoice.reversed'], $effects);
        self::assertSame(0, $this->undone());
    }

    public function testEachLeaseIsTimedFromItsOwnStartSoASlowJobDoesNotExposeTheNextOne(): void
    {
        $this->queue('evt-1', 'invoice:p2_1', 'invoice.success');
        $this->queue('evt-2', 'invoice:p2_2', 'invoice.success');
        $stolen = [];

        $thief = $this->worker(function (array $job) use (&$stolen): void {
            $stolen[] = $job['idempotency_key'];
        }, leaseSeconds: 30);
        $worker = $this->worker(function (array $job) use ($thief): void {
            if ($job['idempotency_key'] === 'evt-1') {
                $this->now += 45;                       // the first job takes longer than the lease
            } else {
                self::assertSame(0, $thief->runOnce(), 'evt-2 was leased at the current time, not batch start');
            }
        }, leaseSeconds: 30);

        // evt-1 outlived its lease but nobody took it over, so its owner may still complete it.
        self::assertSame(2, $worker->runOnce());
        self::assertSame([], $stolen);
        self::assertSame([], $this->undoneKeys());
    }

    public function testOnlyTheLeaseOwnerCanMarkAJobDone(): void
    {
        $this->queue('evt-1', 'invoice:p2_1', 'invoice.success');
        $runs = [];

        $takeover = $this->worker(function (array $job) use (&$runs): void {
            $runs[] = 'takeover';
        }, leaseSeconds: 10);
        $slow = $this->worker(function (array $job) use (&$runs, $takeover): void {
            $runs[] = 'slow';
            $this->now += 11;                            // lease expired while running
            self::assertSame(1, $takeover->runOnce());   // another worker re-leases and completes it
        }, leaseSeconds: 10);

        self::assertSame(0, $slow->runOnce(), 'the original owner must not count or mark the job done');
        self::assertSame(['slow', 'takeover'], $runs);
        self::assertSame(0, $this->undone());
        self::assertSame(2, (int) $this->connect()->query('SELECT attempts FROM webhook_outbox')->fetchColumn());
    }

    public function testAFailedJobIsRetriedInOrderAndBlocksLaterJobsOfItsEntity(): void
    {
        $this->queue('evt-success', 'invoice:p2_1', 'invoice.success');
        $this->queue('evt-reversed', 'invoice:p2_1', 'invoice.reversed');
        $effects = [];
        $failuresLeft = 2;

        $worker = $this->worker(function (array $job) use (&$effects, &$failuresLeft): void {
            if ($job['job_type'] === 'invoice.success' && $failuresLeft-- > 0) {
                throw new RuntimeException('downstream unavailable');
            }

            $effects[] = $job['job_type'];
        }, leaseSeconds: 5);

        self::assertSame(0, $worker->runOnce(), 'success failed; reversed must wait');
        self::assertSame([], $effects);
        self::assertStringContainsString('downstream unavailable', $this->lastError('evt-success'));
        $this->now += 6;
        self::assertSame(0, $worker->runOnce(), 'second failure');
        $this->now += 6;
        self::assertSame(2, $worker->runOnce(), 'success then reversed in one pass');
        self::assertSame(['invoice.success', 'invoice.reversed'], $effects);
        self::assertSame('', $this->lastError('evt-success'), 'last_error is cleared once the job succeeds');
    }

    /** @dataProvider blockedHeadProvider */
    public function testBlockedEntityCannotStarveAnotherEntity(bool $exhausted): void
    {
        $this->queue('blocked-head', 'invoice:blocked', 'invoice.success');
        $this->connect()->prepare(
            'UPDATE webhook_outbox SET attempts = :attempts, leased_until = :until'
        )->execute([':attempts' => $exhausted ? 10 : 1, ':until' => $exhausted ? null : $this->now + 60]);

        for ($i = 0; $i < 50; ++$i) {
            $this->queue('blocked-' . $i, 'invoice:blocked', 'invoice.reversed');
        }
        $this->queue('independent', 'invoice:other', 'invoice.success');
        $effects = [];
        $worker = $this->worker(function (array $job) use (&$effects): void {
            $effects[] = $job['idempotency_key'];
        });

        self::assertSame(1, $worker->runOnce());
        self::assertSame(['independent'], $effects);
        self::assertSame(0, $worker->runOnce());
        self::assertSame(51, $this->undone());
    }

    /** @return array<string, array{bool}> */
    public function blockedHeadProvider(): array
    {
        return ['exhausted attempts' => [true], 'active lease' => [false]];
    }

    public function testRefillingEligibleJobsStillRespectsTheRunLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->queue('evt-' . $i, 'invoice:same', 'invoice.success');
        }
        $effects = [];
        $worker = $this->worker(function (array $job) use (&$effects): void {
            $effects[] = $job['idempotency_key'];
        });

        self::assertSame(3, $worker->runOnce(3));
        self::assertSame(['evt-0', 'evt-1', 'evt-2'], $effects);
        self::assertSame(2, $worker->runOnce(3));
        self::assertSame(0, $this->undone());
    }

    public function testRefillDoesNotRetryTheSameFailedJobWithinOneRun(): void
    {
        $this->queue('slow-failure', 'invoice:failed', 'invoice.success');
        $this->queue('independent', 'invoice:other', 'invoice.success');
        $attempts = [];
        $worker = $this->worker(function (array $job) use (&$attempts): void {
            $attempts[] = $job['idempotency_key'];
            if ($job['idempotency_key'] === 'slow-failure') {
                $this->now += 61; // the lease expired before the dispatcher failed
                throw new RuntimeException('slow downstream failure');
            }
        });

        self::assertSame(1, $worker->runOnce());
        self::assertSame(['slow-failure', 'independent'], $attempts);
        self::assertSame(0, $worker->runOnce());
        self::assertSame(['slow-failure', 'independent', 'slow-failure'], $attempts);
    }

    public function testCompletedJobKeepsOnlyARedactedPayload(): void
    {
        $this->queue('evt-wallet', 'invoice:p2_wallet', 'invoice.wallet.created', json_encode([
            'invoiceId' => 'p2_wallet',
            'walletData' => ['walletId' => 'customer-42', 'cardToken' => 'tok-secret', 'status' => 'created'],
        ], JSON_THROW_ON_ERROR));
        $received = [];

        $completed = $this->worker(static function (array $job) use (&$received): void {
            $received = $job['payload'];
        })->runOnce();

        self::assertSame(1, $completed);
        self::assertSame('tok-secret', $received['walletData']['cardToken'], 'the dispatcher sees the real data');

        $statement = $this->connect()->prepare('SELECT payload FROM webhook_outbox WHERE idempotency_key = :key');
        $statement->execute([':key' => 'evt-wallet']);
        $stored = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('p2_wallet', $stored['invoiceId']);
        self::assertSame('created', $stored['walletData']['status']);
        self::assertSame('[redacted]', $stored['walletData']['cardToken']);
        self::assertSame('[redacted]', $stored['walletData']['walletId']);
    }

    public function testFailedJobKeepsItsPayloadForTheRetry(): void
    {
        $payload = json_encode(['walletData' => ['cardToken' => 'tok-retry']], JSON_THROW_ON_ERROR);
        $this->queue('evt-retry', 'invoice:p2_retry', 'invoice.wallet.created', $payload);

        $this->worker(static function (): void {
            throw new \RuntimeException('temporary');
        })->runOnce();

        $statement = $this->connect()->prepare('SELECT payload FROM webhook_outbox WHERE idempotency_key = :key');
        $statement->execute([':key' => 'evt-retry']);

        self::assertSame($payload, $statement->fetchColumn());
    }

    public function testProcessorCommitsFreshEventsAndRollsBackFailuresOnTheSelectedDatabase(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL is required.');
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $publicKey = base64_encode($details['key']);
        $processor = new TransactionalWebhookProcessor($this->connect(), WebhookService::create(), $publicKey);
        $raw = '{"invoiceId":"database-test","status":"success","modifiedDate":"2026-07-01T12:00:00Z"}';
        openssl_sign($raw, $signature, $key, OPENSSL_ALGO_SHA256);
        self::assertSame('applied', $processor->handle($raw, base64_encode($signature)));
        self::assertSame('duplicate', $processor->handle($raw, base64_encode($signature)));

        $old = str_replace('12:00:00', '11:00:00', $raw);
        openssl_sign($old, $signature, $key, OPENSSL_ALGO_SHA256);
        self::assertSame('stale', $processor->handle($old, base64_encode($signature)));
        self::assertSame(1, $this->undone());

        $next = str_replace(['success', '12:00:00'], ['reversed', '13:00:00'], $raw);
        openssl_sign($next, $signature, $key, OPENSSL_ALGO_SHA256);
        $crashing = new TransactionalWebhookProcessor(
            $this->connect(),
            WebhookService::create(),
            $publicKey,
            static function (): void {
                throw new RuntimeException('simulated crash before commit');
            }
        );
        try {
            $crashing->handle($next, base64_encode($signature));
            self::fail('The transaction hook must fail');
        } catch (RuntimeException $e) {
            self::assertSame('simulated crash before commit', $e->getMessage());
        }
        self::assertSame(1, $this->undone(), 'the failed transaction must not enqueue a job');
        self::assertSame('applied', $processor->handle($next, base64_encode($signature)));
        self::assertSame(2, $this->worker(static function (): void {
        })->runOnce());
        self::assertSame(0, $this->undone());
    }

    private function clearTables(PDO $pdo): void
    {
        foreach (['webhook_outbox', 'webhook_entities', 'webhook_events'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }

    private function worker(callable $dispatcher, int $leaseSeconds = 60): OutboxWorker
    {
        return new OutboxWorker($this->connect(), $dispatcher, $leaseSeconds, 10, fn (): int => $this->now);
    }

    private function queue(string $key, string $entity, string $type, string $payload = '{}'): void
    {
        $this->connect()->prepare(
            'INSERT INTO webhook_outbox (idempotency_key, entity_key, job_type, payload, created_at) '
            . 'VALUES (:key, :entity, :type, :payload, :now)'
        )->execute([':key' => $key, ':entity' => $entity, ':type' => $type, ':payload' => $payload, ':now' => 'now']);
    }

    private function undone(): int
    {
        $statement = $this->connect()->query('SELECT COUNT(*) FROM webhook_outbox WHERE done_at IS NULL');

        return (int) $statement->fetchColumn();
    }

    /** @return string[] */
    private function undoneKeys(): array
    {
        return $this->connect()
            ->query('SELECT idempotency_key FROM webhook_outbox WHERE done_at IS NULL ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function lastError(string $key): string
    {
        $statement = $this->connect()->prepare('SELECT last_error FROM webhook_outbox WHERE idempotency_key = :key');
        $statement->execute([':key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : '';
    }

    private function connect(): PDO
    {
        if ($this->externalDsn !== null) {
            return new PDO(
                $this->externalDsn,
                getenv('MONO_WEBHOOK_TEST_USER') ?: 'root',
                getenv('MONO_WEBHOOK_TEST_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
        }
        return new PDO('sqlite:' . $this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
