<?php

namespace Vladchornyi\Mono\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vladchornyi\Mono\Examples\Webhooks\OutboxWorker;
use Vladchornyi\Mono\Examples\Webhooks\TransactionalWebhookProcessor;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Services\WebhookService;

/**
 * Exercises the reference integration in examples/Webhooks against SQLite:
 * duplicate and out-of-order deliveries, a crash between the event-key write
 * and the state change, two concurrent deliveries, and outbox execution.
 */
final class TransactionalWebhookProcessorTest extends TestCase
{
    private string $databasePath;
    private string $publicKey;
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;

    protected function setUp(): void
    {
        if (!extension_loaded('openssl') || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('OpenSSL and pdo_sqlite are required.');
        }

        $this->databasePath = tempnam(sys_get_temp_dir(), 'mono-webhooks-') . '.sqlite';
        $pdo = $this->connect();
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/examples/Webhooks/schema.sql'));

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->privateKey = $key;
        $this->publicKey = base64_encode($details['key']);
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
    }

    public function testRedeliveryAndOutOfOrderEventsChangeStateExactlyOnce(): void
    {
        $pdo = $this->connect();
        $processor = $this->processor($pdo);

        $processing = $this->event(['status' => 'processing', 'modifiedDate' => '2026-07-01T10:00:00Z']);
        $success = $this->event(['status' => 'success', 'modifiedDate' => '2026-07-01T10:00:05.123456789Z']);

        // success arrives before processing (Monobank gives no ordering guarantee)
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $processor->handle(...$success));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_STALE, $processor->handle(...$processing));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_DUPLICATE, $processor->handle(...$success));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_DUPLICATE, $processor->handle(...$processing));

        self::assertSame('success', $this->entityStatus($pdo));
        self::assertSame(1, $this->rowCount($pdo, 'webhook_outbox'));
        self::assertSame(2, $this->rowCount($pdo, 'webhook_events'));

        // Events 800 ns apart are still ordered: the stored timestamp keeps nanoseconds.
        $laterByNanos = $this->event(['status' => 'reversed', 'modifiedDate' => '2026-07-01T10:00:05.123456900Z']);
        $earlierByNanos = $this->event(['status' => 'hold', 'modifiedDate' => '2026-07-01T10:00:05.123456100Z']);
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $processor->handle(...$laterByNanos));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_STALE, $processor->handle(...$earlierByNanos));
        self::assertSame('reversed', $this->entityStatus($pdo));

        $this->expectException(MonoWebhookException::class);
        $processor->handle($success[0], 'not-a-signature');
    }

    public function testEventsWithoutDateOrStatusNeverEraseTheLastKnownState(): void
    {
        $pdo = $this->connect();
        $processor = $this->processor($pdo);

        $success = $this->event(['status' => 'success', 'modifiedDate' => '2026-07-01T12:00:00Z']);
        $tokenization = $this->event([
            'walletData' => ['walletId' => 'w1', 'cardToken' => 'tok', 'status' => 'created'],
        ], withStatus: false);
        $staleProcessing = $this->event(['status' => 'processing', 'modifiedDate' => '2026-07-01T11:00:00Z']);

        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $processor->handle(...$success));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $processor->handle(...$tokenization));

        $entity = $this->entity($pdo);
        self::assertSame('success', $entity['status'], 'a tokenization update must not clear the payment status');
        self::assertSame('2026-07-01T12:00:00.000000000Z', $entity['modified_at'], 'nor the stale-protection date');
        self::assertSame('created', $entity['wallet_status']);
        self::assertSame('w1', $entity['wallet_id']);

        self::assertSame(TransactionalWebhookProcessor::OUTCOME_STALE, $processor->handle(...$staleProcessing));
        self::assertSame('success', $this->entity($pdo)['status']);

        $jobs = $pdo->query('SELECT job_type FROM webhook_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['invoice.success', 'invoice.wallet.created'], $jobs, 'the stale event queued nothing');
    }

    public function testCrashBetweenEventKeyAndStateChangeLeavesNothingBehind(): void
    {
        $pdo = $this->connect();
        $crashing = $this->processor($pdo, static function (): void {
            throw new RuntimeException('simulated process crash before commit');
        });
        $success = $this->event(['status' => 'success', 'modifiedDate' => '2026-07-01T10:00:05Z']);

        try {
            $crashing->handle(...$success);
            self::fail('The simulated crash did not propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('simulated process crash before commit', $exception->getMessage());
        }

        // Nothing was committed: no event key, no entity, no job, no open transaction.
        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $this->rowCount($pdo, 'webhook_events'));
        self::assertSame(0, $this->rowCount($pdo, 'webhook_entities'));
        self::assertSame(0, $this->rowCount($pdo, 'webhook_outbox'));

        // Monobank's redelivery is processed normally.
        $healthy = $this->processor($pdo);
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $healthy->handle(...$success));
        self::assertSame('success', $this->entityStatus($pdo));
        self::assertSame(1, $this->rowCount($pdo, 'webhook_outbox'));
    }

    public function testConcurrentDeliveriesSerializeOnTheEntityLock(): void
    {
        $success = $this->event(['status' => 'success', 'modifiedDate' => '2026-07-01T10:00:05Z']);
        $reversed = $this->event(['status' => 'reversed', 'modifiedDate' => '2026-07-01T11:00:00Z']);
        $concurrentOutcomes = [];

        $first = $this->processor(
            $this->connect(),
            function () use (&$concurrentOutcomes, $success, $reversed): void {
                // While the first delivery holds its uncommitted transaction, a second
                // worker receives the same event and a newer one for the same invoice.
                $second = $this->processor($this->connect(0));
                $concurrentOutcomes[] = $second->handle(...$success);
                $concurrentOutcomes[] = $second->handle(...$reversed);
            }
        );

        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $first->handle(...$success));
        self::assertSame(
            [TransactionalWebhookProcessor::OUTCOME_RETRY, TransactionalWebhookProcessor::OUTCOME_RETRY],
            $concurrentOutcomes,
            'Concurrent deliveries must be asked to retry, never processed twice or lost.'
        );

        // After the first commit the retried deliveries resolve deterministically.
        $pdo = $this->connect();
        $second = $this->processor($pdo);
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_DUPLICATE, $second->handle(...$success));
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $second->handle(...$reversed));
        self::assertSame('reversed', $this->entityStatus($pdo));
        self::assertSame(2, $this->rowCount($pdo, 'webhook_outbox'));
        self::assertSame(2, $this->rowCount($pdo, 'webhook_events'));
    }

    public function testOutboxRunsEachJobOnceAndRetriesFailuresWithTheSameIdempotencyKey(): void
    {
        $pdo = $this->connect();
        $processor = $this->processor($pdo);
        $success = $this->event(['status' => 'success', 'modifiedDate' => '2026-07-01T10:00:05Z']);
        self::assertSame(TransactionalWebhookProcessor::OUTCOME_APPLIED, $processor->handle(...$success));

        $calls = [];
        $failFirst = true;
        $worker = new OutboxWorker($pdo, static function (array $job) use (&$calls, &$failFirst): void {
            $calls[] = $job['idempotency_key'];

            if ($failFirst) {
                $failFirst = false;
                throw new RuntimeException('downstream unavailable');
            }
        }, leaseSeconds: 0);

        self::assertSame(0, $worker->runOnce());   // first attempt fails, job stays queued
        self::assertSame(1, $worker->runOnce());   // retried after the lease expired
        self::assertSame(0, $worker->runOnce());   // done jobs are never re-run
        self::assertCount(2, $calls);
        self::assertSame($calls[0], $calls[1]);
        self::assertStringStartsWith('evt:v2:', $calls[0]);
        self::assertSame(1, $this->rowCount($pdo, 'webhook_outbox', 'done_at IS NOT NULL'));
    }

    private function connect(?int $busyTimeoutSeconds = null): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        if ($busyTimeoutSeconds !== null) {
            $options[PDO::ATTR_TIMEOUT] = $busyTimeoutSeconds;
        }

        return new PDO('sqlite:' . $this->databasePath, null, null, $options);
    }

    private function processor(PDO $pdo, ?callable $beforeCommit = null): TransactionalWebhookProcessor
    {
        return new TransactionalWebhookProcessor($pdo, new WebhookService(), $this->publicKey, $beforeCommit);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: string, 1: string} raw body and X-Sign
     */
    private function event(array $fields, bool $withStatus = true): array
    {
        $base = ['invoiceId' => 'p2_order_1', 'amount' => 100, 'ccy' => 980];
        $body = json_encode($withStatus ? $base + $fields : $fields + $base, JSON_THROW_ON_ERROR);
        self::assertTrue(openssl_sign($body, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        return [$body, base64_encode($signature)];
    }

    /**
     * @return array<string, mixed>
     */
    private function entity(PDO $pdo): array
    {
        $statement = $pdo->query("SELECT * FROM webhook_entities WHERE entity_key = 'invoice:p2_order_1'");
        $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : false;

        return is_array($row) ? $row : [];
    }

    private function entityStatus(PDO $pdo): ?string
    {
        $status = $pdo->query("SELECT status FROM webhook_entities WHERE entity_key = 'invoice:p2_order_1'");
        $value = $status !== false ? $status->fetchColumn() : false;

        return is_string($value) ? $value : null;
    }

    private function rowCount(PDO $pdo, string $table, string $where = '1 = 1'): int
    {
        $statement = $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}");

        return $statement !== false ? (int) $statement->fetchColumn() : -1;
    }
}
