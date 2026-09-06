<?php

namespace Vladchornyi\Mono\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Tests\Support\EcdsaKeys;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;
use Vladchornyi\Mono\Webhooks\CachedPublicKeyProvider;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

/**
 * One test per defect that was reproduced during the 2.0 review. Test names
 * describe the defect; each test is the minimal reproduction and must keep
 * failing if the fix regresses.
 */
final class ReproducedDefectsTest extends TestCase
{
    private const BASE = 'https://example.test/api/merchant';
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    protected function setUp(): void
    {
        foreach (
            [
                [CachedPublicKeyProvider::class, 'processGuards', []],
                [MonoClient::class, 'processLocalCacheWarned', false],
                [ArrayPublicKeyCache::class, 'processShared', null],
            ] as [$class, $property, $value]
        ) {
            $reflection = new \ReflectionProperty($class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $value);
        }
    }

    public function testSecondClientForTheSameMerchantWithinTheRefreshCooldownWasRateLimited(): void
    {
        $this->requireOpenSsl();
        [$privateKey, $publicKey] = EcdsaKeys::pair();
        $body = '{"invoiceId":"p2_1","status":"success"}';
        $xSign = EcdsaKeys::sign($body, $privateKey);

        $first = new MonoClient('token', self::BASE, new FakeHttpClient(
            new HttpResponse(200, json_encode(['key' => $publicKey], JSON_THROW_ON_ERROR))
        ));
        self::assertTrue(@$first->webhooks()->verify($body, $xSign));

        $second = new MonoClient('token', self::BASE, new FakeHttpClient());
        self::assertTrue($second->webhooks()->verify($body, $xSign));
    }

    public function testCardTokenWalletIdAndEmailLeakedIntoTheApiExceptionMessage(): void
    {
        $calls = [
            'SECRET-TOKEN' => static fn (MonoClient $m) => $m->wallet()->deleteCard('SECRET-TOKEN'),
            'SECRET-WALLET' => static fn (MonoClient $m) => $m->wallet()->getCards('SECRET-WALLET'),
            'secret%40example.com' => static fn (MonoClient $m) => $m->invoices()->getReceipt(
                'p2_1',
                'secret@example.com'
            ),
        ];

        foreach ($calls as $marker => $call) {
            $mono = new MonoClient('token', self::BASE, new FakeHttpClient(new HttpResponse(400, '{}')));

            try {
                $call($mono);
                self::fail('No exception');
            } catch (MonoApiException $exception) {
                self::assertStringNotContainsString($marker, $exception->getMessage());
                self::assertStringNotContainsString($marker, (string) json_encode($exception->getSafeContext()));
                self::assertStringNotContainsString(rawurldecode($marker), $exception->getMessage());
            }
        }
    }

    public function testSparseBasketSerializedAsAJsonObjectAndEmptyMetadataAsAJsonList(): void
    {
        $items = [new BasketOrderItem('a', 1, 100, 'A'), new BasketOrderItem('b', 1, 100, 'B')];
        unset($items[0]);
        $invoice = new InvoiceData(100, merchantPaymInfo: new MerchantPaymInfoItem(basketOrder: $items, metadata: []));

        $json = json_encode($invoice->toArray(), self::ENCODE_FLAGS);

        self::assertStringContainsString('"basketOrder":[{"name":"b"', $json);
        self::assertStringNotContainsString('"basketOrder":{"1"', $json);
        self::assertStringNotContainsString('"metadata":[]', $json);
    }

    public function testAllowMissingModifiedDateFalseDidNotRejectWhenNothingWasStored(): void
    {
        $webhooks = WebhookService::create();

        self::assertFalse($webhooks->shouldApply(['invoiceId' => 'p2_1'], null, false));
    }

    public function testNanosecondTruncationMadeALaterEventLookNotNewer(): void
    {
        $webhooks = WebhookService::create();

        self::assertTrue($webhooks->shouldApply(
            ['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123456900Z'],
            '2026-07-01T10:00:00.123456100Z'
        ));
        self::assertFalse($webhooks->shouldApply(
            ['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123456100Z'],
            '2026-07-01T10:00:00.123456900Z'
        ));
    }

    public function testNineDigitFractionsWereRejectedAsInvalidTimestamps(): void
    {
        $event = WebhookPayload::fromArray(['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123456789Z']);

        self::assertNotNull($event->modifiedDate());
        self::assertSame(123456789, $event->modifiedInstant()?->nanoseconds());
    }

    public function testOutOfRangeTimezoneOffsetsWereNormalisedInsteadOfRejected(): void
    {
        self::assertNull(\Vladchornyi\Mono\Support\Rfc3339::parse('2026-07-01T10:00:00+24:00'));
        self::assertNull(\Vladchornyi\Mono\Support\Rfc3339::parse('2026-07-01T10:00:00+00:99'));
        self::assertNull(\Vladchornyi\Mono\Support\Instant::parse('2026-07-01T10:00:00+00:99'));
        self::assertNotNull(\Vladchornyi\Mono\Support\Rfc3339::parse('2026-07-01T10:00:00-23:59'));
    }

    public function testNamedLegacyConstructorArgumentsWereUnknownParameters(): void
    {
        $mono = new MonoClient('token', self::BASE, new FakeHttpClient());

        $service = @new WebhookService(pubkeyService: $mono->pubkey(), verifier: new WebhookVerifier());

        self::assertInstanceOf(WebhookService::class, $service);
    }

    public function testDiscountTaxWasRestrictedToIntegersAlthoughTheSchemaLeavesItUntyped(): void
    {
        $discount = new DiscountItem(
            DiscountItem::TYPE_DISCOUNT,
            DiscountItem::MODE_VALUE,
            10,
            tax: [8, ['rate' => 20]]
        );

        self::assertSame(
            '{"type":"DISCOUNT","mode":"VALUE","value":10.0,"tax":[8,{"rate":20}]}',
            json_encode($discount->toArray(), self::ENCODE_FLAGS)
        );
    }

    public function testValidityOverThirtyDaysThrewAlthoughMonobankClamps(): void
    {
        self::assertSame(2592000, (new InvoiceData(100, validity: 31 * 86400))->toArray()['validity']);
    }

    public function testFinalizeHoldAllowedAMissingAmountThatTheSchemaRequires(): void
    {
        $reflection = new \ReflectionMethod(\Vladchornyi\Mono\Services\InvoiceService::class, 'finalizeHold');

        self::assertFalse($reflection->getParameters()[1]->isOptional());
        self::assertSame('int', (string) $reflection->getParameters()[1]->getType());
    }

    public function testCancelSubscriptionCalledTheRemoveEndpointThatFailsAfterTheFirstCharge(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'));
        (new MonoClient('token', self::BASE, $http))->subscriptions()->cancelSubscription('s1', 100);

        self::assertStringEndsWith('/subscription/edit', $http->requests[0]['url']);
        self::assertSame(100, $http->requests[0]['body']['refundAmount']);
    }

    public function testEventKeyRemovalBrokeOneXHandlers(): void
    {
        self::assertSame('invoice:p2_1', @WebhookPayload::fromArray(['invoiceId' => 'p2_1'])->eventKey());
    }

    private function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL is not installed.');
        }
    }
}
