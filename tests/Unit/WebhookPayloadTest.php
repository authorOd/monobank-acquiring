<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Webhooks\WebhookPayload;

final class WebhookPayloadTest extends TestCase
{
    public function testDeduplicationKeyIsMemoizedPerInstance(): void
    {
        $payload = new class (['invoiceId' => 'p2_same', 'status' => 'processing']) extends WebhookPayload {
            private int $entityKeyCalls = 0;

            public function entityKey(): string
            {
                ++$this->entityKeyCalls;

                return parent::entityKey();
            }

            public function entityKeyCalls(): int
            {
                return $this->entityKeyCalls;
            }
        };

        self::assertStringStartsWith('evt:v2:', $payload->deduplicationKey());
        self::assertSame($payload->deduplicationKey(), $payload->deduplicationKey());
        self::assertSame(1, $payload->entityKeyCalls());
    }

    public function testDeduplicationKeyDeduplicatesRetriesButNotStateChanges(): void
    {
        $processing = WebhookPayload::fromArray([
            'invoiceId' => 'p2_same',
            'status' => 'processing',
            'modifiedDate' => '2026-07-01T10:00:00Z',
            'items' => [['code' => 'one'], ['code' => 'two']],
        ]);
        $sameRetryWithDifferentKeyOrder = WebhookPayload::fromArray([
            'modifiedDate' => '2026-07-01T10:00:00Z',
            'status' => 'processing',
            'invoiceId' => 'p2_same',
            'items' => [['code' => 'one'], ['code' => 'two']],
        ]);
        $success = WebhookPayload::fromArray([
            'invoiceId' => 'p2_same',
            'status' => 'success',
            'modifiedDate' => '2026-07-01T10:01:00Z',
        ]);

        self::assertSame('invoice:p2_same', $processing->entityKey());
        self::assertStringStartsWith('evt:v2:', $processing->deduplicationKey());
        self::assertSame($processing->deduplicationKey(), $sameRetryWithDifferentKeyOrder->deduplicationKey());
        self::assertNotSame($processing->deduplicationKey(), $success->deduplicationKey());
    }

    public function testStatusPredicatesRespectPayloadType(): void
    {
        $subscription = WebhookPayload::fromArray([
            'subscriptionId' => 's2_1',
            'status' => 'created',
        ]);
        $invoice = WebhookPayload::fromArray([
            'invoiceId' => 'p2_1',
            'status' => 'created',
        ]);

        self::assertTrue($subscription->isSubscriptionStatus());
        self::assertFalse($subscription->isInvoiceStatus());
        self::assertTrue($invoice->isInvoiceStatus());
        self::assertFalse($invoice->isSubscriptionStatus());

        $unknown = WebhookPayload::fromArray(['status' => 'success']);
        self::assertFalse($unknown->isSuccessful());
        self::assertFalse($unknown->isFailure());
    }

    /**
     * @dataProvider invalidIntegerProvider
     * @param mixed $value
     */
    public function testFinancialIntegersAreNeverSilentlyTruncated($value): void
    {
        $payload = WebhookPayload::fromArray(['invoiceId' => 'p2_1', 'amount' => $value]);

        self::assertNull($payload->amount());
    }

    /** @return array<string, array{mixed}> */
    public function invalidIntegerProvider(): array
    {
        return [
            'decimal string' => ['10.99'],
            'float' => [1.9],
            'overflow' => ['999999999999999999999999999999999999'],
            'scientific' => ['1e3'],
        ];
    }

    public function testDatesMustBeStrictRfc3339(): void
    {
        $payload = WebhookPayload::fromArray([
            'invoiceId' => 'p2_1',
            'modifiedDate' => '2026-02-30T10:00:00Z',
        ]);

        $this->expectException(MonoWebhookException::class);
        $payload->modifiedDate();
    }

    public function testNanosecondFractionsAreTruncatedAndLongerOnesRejected(): void
    {
        $payload = WebhookPayload::fromArray([
            'invoiceId' => 'p2_1',
            'modifiedDate' => '2026-07-01T10:00:00.123456789Z',
        ]);

        self::assertSame('123456', $payload->modifiedDate()?->format('u'));

        $payload = WebhookPayload::fromArray([
            'invoiceId' => 'p2_1',
            'modifiedDate' => '2026-07-01T10:00:00.1234567890Z',
        ]);

        $this->expectException(MonoWebhookException::class);
        $payload->modifiedDate();
    }

    public function testTypedAccessorsExposeDocumentedWebhookFields(): void
    {
        $data = [
            'subscriptionId' => 's2_1',
            'invoiceId' => 'p2_charge_1',
            'status' => 'failure',
            'amount' => 100,
            'finalAmount' => '90',
            'ccy' => 980,
            'interval' => '1m',
            'payMethod' => 'wallet',
            'failureReason' => 'declined',
            'cancellationDesc' => 'cancelled',
            'createdDate' => '2026-07-01T10:00:00Z',
            'modifiedDate' => '2026-07-01T10:01:00Z',
            'startDate' => '2026-07-01T10:00:00Z',
            'endDate' => '2027-07-01T10:00:00Z',
            'nextChargeDate' => '2026-08-01T10:00:00Z',
            'summary' => ['totalPaid' => 2, 'totalFailed' => 1],
            'walletData' => ['walletId' => 'secret-wallet'],
            'paymentInfo' => ['rrn' => 'secret-rrn'],
        ];
        $payload = WebhookPayload::fromArray($data);

        self::assertSame(WebhookPayload::TYPE_SUBSCRIPTION_CHARGE, $payload->type());
        self::assertSame($data, $payload->toArray());
        self::assertSame('[redacted]', $payload->safeArray()['walletData']['walletId']);
        self::assertSame('p2_charge_1', $payload->invoiceId());
        self::assertSame('s2_1', $payload->subscriptionId());
        self::assertSame('failure', $payload->status());
        self::assertSame(100, $payload->amount());
        self::assertSame(90, $payload->finalAmount());
        self::assertSame(980, $payload->ccy());
        self::assertSame('1m', $payload->interval());
        self::assertSame('wallet', $payload->payMethod());
        self::assertSame('declined', $payload->failureReason());
        self::assertSame('cancelled', $payload->cancellationDesc());
        self::assertNotNull($payload->createdDate());
        self::assertNotNull($payload->modifiedDate());
        self::assertNotNull($payload->startDate());
        self::assertNotNull($payload->endDate());
        self::assertNotNull($payload->nextChargeDate());
        self::assertSame($data['summary'], $payload->summary());
        self::assertSame(2, $payload->totalPaid());
        self::assertSame(1, $payload->totalFailed());
        self::assertSame($data['walletData'], $payload->walletData());
        self::assertSame($data['paymentInfo'], $payload->paymentInfo());
        self::assertSame('subscription_charge:p2_charge_1', $payload->entityKey());
        self::assertTrue($payload->isInvoiceStatus());
        self::assertTrue($payload->isFailure());
        self::assertTrue($payload->isTerminalInvoiceStatus());
    }

    public function testInvalidOptionalPayloadShapesReturnSafeDefaults(): void
    {
        $payload = WebhookPayload::fromArray([
            'subscriptionId' => 's2_1',
            'ccy' => 1000,
            'createdDate' => null,
            'summary' => 'invalid',
            'walletData' => 'invalid',
            'paymentInfo' => 'invalid',
        ]);

        self::assertSame('subscription_status:s2_1', $payload->entityKey());
        self::assertNull($payload->ccy());
        self::assertNull($payload->createdDate());
        self::assertSame([], $payload->summary());
        self::assertSame([], $payload->walletData());
        self::assertSame([], $payload->paymentInfo());
        self::assertSame('default', $payload->get('missing.path', 'default'));

        $unknown = WebhookPayload::fromArray([]);
        self::assertSame(WebhookPayload::TYPE_UNKNOWN, $unknown->entityKey());
    }

    public function testLegacyEventKeyAliasReturnsTheEntityKeyWithADeprecation(): void
    {
        $payload = WebhookPayload::fromArray(['invoiceId' => 'p2_1', 'status' => 'success']);
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = [$errno, $message];

            return true;
        });

        try {
            $key = $payload->eventKey();
        } finally {
            restore_error_handler();
        }

        self::assertSame('invoice:p2_1', $key);
        self::assertSame($payload->entityKey(), $key);
        self::assertCount(1, $messages);
        self::assertSame(E_USER_DEPRECATED, $messages[0][0]);
    }

    public function testSubscriptionChargeAcceptsPaymentVocabularyAndExposesErrorDetails(): void
    {
        $charge = WebhookPayload::fromArray([
            'subscriptionId' => 's2_1',
            'invoiceId' => 'p2_1',
            'status' => 'failed',
            'errCode' => '59',
            'failureReason' => 'Insufficient funds',
            'tipsInfo' => ['employeeId' => 'emp-1', 'amount' => 100],
        ]);

        self::assertSame(WebhookPayload::TYPE_SUBSCRIPTION_CHARGE, $charge->type());
        self::assertTrue($charge->isInvoiceStatus());
        self::assertTrue($charge->isFailure());
        self::assertTrue($charge->isTerminalInvoiceStatus());
        self::assertSame('59', $charge->errCode());
        self::assertSame(['employeeId' => 'emp-1', 'amount' => 100], $charge->tipsInfo());

        $undocumentedSubscriptionStatus = WebhookPayload::fromArray([
            'subscriptionId' => 's2_1',
            'status' => 'suspended',
        ]);
        self::assertTrue($undocumentedSubscriptionStatus->isSubscriptionStatus());
        self::assertFalse($undocumentedSubscriptionStatus->isFailure());
    }
}
