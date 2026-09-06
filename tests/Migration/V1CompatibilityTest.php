<?php

namespace Vladchornyi\Mono\Tests\Migration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Exceptions\MonoException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Tests\Support\EcdsaKeys;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;
use Vladchornyi\Mono\Webhooks\CachedPublicKeyProvider;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

/**
 * Real 1.x call forms, taken from the v1.4.0 README and docs, executed
 * against 2.0. Each test states the 1.x behaviour and what 2.0 guarantees.
 * These are the release gate for UPGRADING.md.
 */
final class V1CompatibilityTest extends TestCase
{
    private const BASE = 'https://example.test/api/merchant';

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

    public function testFourArgumentPositionalClientConstructorAndAccessors(): void
    {
        $http = new FakeHttpClient(...array_fill(0, 5, new HttpResponse(200, '{"ok":true}')));
        $mono = new MonoClient('token', self::BASE, $http, ['timeout' => 5]);

        $mono->invoices()->getInvoiceStatus('p2_1');
        $mono->statements()->getStatement(1, 2);
        $mono->merchant()->getDetails();
        $mono->pubkey()->get();
        $mono->subscriptions()->getSubscriptionStatus('s1');

        self::assertCount(5, $http->requests);
        self::assertSame('token', $http->requests[0]['headers']['X-Token']);
        self::assertSame('application/json', $http->requests[0]['headers']['Accept']);
        self::assertArrayNotHasKey(0, $http->requests[0]['headers'], '1.x sent a broken numeric Content-Type entry');
    }

    public function testOneXInvoiceCreationWithArraySaveCardDataAndFiscalization(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"invoiceId":"p2_1","pageUrl":"https://pay"}'));
        $mono = new MonoClient('token', self::BASE, $http);

        // 1.x README example, verbatim shape (array saveCardData, positional basket item).
        $invoice = $mono->invoices()->createInvoice(new InvoiceData(
            10000,
            'https://example.com/return',
            'https://example.com/hook',
            ['saveCard' => true, 'walletId' => 'w1'],
            new MerchantPaymInfoItem('ref', 'dest', null, null, null, [
                new BasketOrderItem('Item', 1, 10000, 'SKU'),
            ]),
            980,
            3600,
            'debit'
        ));

        self::assertSame('p2_1', $invoice['invoiceId']);
        self::assertSame(['saveCard' => true, 'walletId' => 'w1'], $http->requests[0]['body']['saveCardData']);
        self::assertSame(10000, $http->requests[0]['body']['merchantPaymInfo']['basketOrder'][0]['total']);
    }

    public function testOneXFinalizeHoldSignatureIsUnchanged(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"status":"success"}'));
        $mono = new MonoClient('token', self::BASE, $http);

        $mono->invoices()->finalizeHold('p2_1', 4200);

        self::assertSame(['invoiceId' => 'p2_1', 'amount' => 4200], $http->requests[0]['body']);
    }

    public function testOneXSubscriptionDataCallStillWorks(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"subscriptionId":"s1","pageUrl":"https://pay"}'));
        $mono = new MonoClient('token', self::BASE, $http);

        $mono->subscriptions()->createSubscription(new SubscriptionData(
            59000,
            980,
            'https://example.com/return',
            ['chargeUrl' => 'https://example.com/c', 'statusUrl' => 'https://example.com/s'],
            '1m'
        ));

        self::assertSame('1m', $http->requests[0]['body']['interval']);
    }

    public function testCancelSubscriptionChangedEndpointAndRemoveSubscriptionKeepsTheOldOne(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'), new HttpResponse(200, '{}'));
        $mono = new MonoClient('token', self::BASE, $http);

        $mono->subscriptions()->cancelSubscription('s1');      // 1.x: POST /subscription/remove
        $mono->subscriptions()->removeSubscription('s1');      // 2.0 equivalent of the 1.x call

        self::assertSame(self::BASE . '/subscription/edit', $http->requests[0]['url']);
        self::assertSame(['subscriptionId' => 's1', 'action' => 'cancel'], $http->requests[0]['body']);
        self::assertSame(self::BASE . '/subscription/remove', $http->requests[1]['url']);
    }

    public function testOneXWebhookHandlerWithoutAnyCacheConfigurationStillVerifies(): void
    {
        $this->requireOpenSsl();
        [$privateKey, $publicKey] = EcdsaKeys::pair();
        $body = '{"invoiceId":"p2_1","status":"success","modifiedDate":"2026-07-01T10:00:00Z"}';
        $xSign = EcdsaKeys::sign($body, $privateKey);
        $http = new FakeHttpClient(new HttpResponse(200, json_encode(['key' => $publicKey], JSON_THROW_ON_ERROR)));

        // 1.x docs: new MonoClient($key); $mono->webhooks()->parseVerified($rawBody, $xSign)
        $mono = new MonoClient('token', self::BASE, $http);
        $payload = @$mono->webhooks()->parseVerified($body, $xSign);
        self::assertSame('success', $payload['status']);

        $event = $mono->webhooks()->parseVerifiedPayload($body, $xSign);
        self::assertTrue($event->isSuccessful());
        self::assertSame('invoice:p2_1', @$event->eventKey());
        self::assertTrue($mono->webhooks()->shouldApply($payload, new DateTimeImmutable('2026-07-01T09:00:00Z')));
        self::assertTrue($mono->webhooks()->shouldApply(
            ['invoiceId' => 'p2_1'],
            new DateTimeImmutable('2026-07-01T09:00:00Z')
        ));
        self::assertCount(1, $http->requests, 'one /pubkey fetch, then cached');
    }

    public function testStoredDateTimeKeepsOneXMicrosecondReplayDetection(): void
    {
        $webhooks = WebhookService::create();
        $event = ['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123456789Z'];

        // 1.x stored $payload->modifiedDate() (a DateTime, microseconds only) and rejected the redelivery.
        $storedAsInOneX = WebhookPayload::fromArray($event)->modifiedDate();
        self::assertNotNull($storedAsInOneX);
        self::assertSame('123456', $storedAsInOneX->format('u'));

        self::assertFalse($webhooks->shouldApply($event, $storedAsInOneX), 'redelivery must stay a replay');
        self::assertTrue($webhooks->shouldApply(
            ['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123457Z'],
            $storedAsInOneX
        ));

        // Storing the raw string (2.0 recommendation) adds nanosecond ordering without changing the above.
        self::assertFalse($webhooks->shouldApply($event, $event['modifiedDate']));
        self::assertTrue($webhooks->shouldApply(
            ['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00.123456790Z'],
            $event['modifiedDate']
        ));
    }

    public function testOneXKeyRotationHandlerAndItsTwoReplacements(): void
    {
        $this->requireOpenSsl();
        [$oldPrivate, $oldPublic] = EcdsaKeys::pair();
        [$newPrivate, $newPublic] = EcdsaKeys::pair();
        $body = '{"invoiceId":"p2_1","status":"success"}';
        $signedWithNewKey = EcdsaKeys::sign($body, $newPrivate);
        $keyResponse = static fn (string $key): HttpResponse => new HttpResponse(
            200,
            json_encode(['key' => $key], JSON_THROW_ON_ERROR)
        );

        // The SDK cache holds the old key (a verification before the rotation warmed it).
        $http = new FakeHttpClient($keyResponse($oldPublic), $keyResponse($newPublic), $keyResponse($newPublic));
        $webhooks = @(new MonoClient('token', self::BASE, $http))->webhooks();
        self::assertTrue($webhooks->verify($body, EcdsaKeys::sign($body, $oldPrivate)));
        $cachedPublicKey = $oldPublic;   // the application's own cache, as in the 1.x docs

        // 1.x docs, verbatim: retry with getPublicKey(). It now returns the cached old key.
        try {
            $webhooks->parseVerified($body, $signedWithNewKey, $cachedPublicKey);
            self::fail('old key accepted a new-key signature');
        } catch (MonoWebhookException $exception) {
            $freshPublicKey = $webhooks->getPublicKey();
            self::assertSame($oldPublic, $freshPublicKey, 'getPublicKey() is a cached read in 2.0');
        }

        try {
            $webhooks->parseVerified($body, $signedWithNewKey, $freshPublicKey);
            self::fail('the 1.x handler must be shown to fail, otherwise UPGRADING.md is wrong');
        } catch (MonoWebhookException $exception) {
            self::assertCount(1, $http->requests, 'no /pubkey request was made by the 1.x pattern');
        }

        // Replacement B: refreshPublicKey() forces the fetch and returns the rotated key.
        $rotated = $webhooks->refreshPublicKey();
        self::assertSame($newPublic, $rotated);
        self::assertSame('success', $webhooks->parseVerified($body, $signedWithNewKey, $rotated)['status']);
        self::assertCount(2, $http->requests);

        // Replacement A: no explicit key; the SDK rotates by itself on a fresh client with a stale cache.
        $this->setUp();
        $auto = new FakeHttpClient($keyResponse($oldPublic), $keyResponse($newPublic));
        $automatic = @(new MonoClient('token', self::BASE, $auto))->webhooks();
        self::assertTrue($automatic->verify($body, EcdsaKeys::sign($body, $oldPrivate)));
        self::assertSame('success', $automatic->parseVerified($body, $signedWithNewKey)['status']);
        self::assertCount(2, $auto->requests, 'one warm-up fetch, one rotation refresh');
    }

    public function testOneXWebhookServiceConstructionForms(): void
    {
        $this->requireOpenSsl();
        [$privateKey, $publicKey] = EcdsaKeys::pair();
        $body = '{"invoiceId":"p2_1","status":"success"}';
        $xSign = EcdsaKeys::sign($body, $privateKey);
        $http = new FakeHttpClient(new HttpResponse(200, json_encode(['key' => $publicKey], JSON_THROW_ON_ERROR)));
        $mono = new MonoClient('token', self::BASE, $http);
        $pubkey = $mono->pubkey();

        $services = [
            'positional' => @new WebhookService($pubkey, new WebhookVerifier()),
            'positional, one argument' => @new WebhookService($pubkey),
            'named' => @new WebhookService(pubkeyService: $pubkey, verifier: new WebhookVerifier()),
            'mixed' => @new WebhookService($pubkey, verifier: new WebhookVerifier()),
        ];

        foreach ($services as $form => $service) {
            self::assertTrue($service->verify($body, $xSign), $form);
        }

        self::assertCount(1, $http->requests, 'the process-shared cache serves every instance');
    }

    public function testOneXExceptionHandlingStillCatchesWebhookFailures(): void
    {
        $webhooks = WebhookService::create();

        foreach (['not json', '[]', '{}', ''] as $payload) {
            try {
                $webhooks->parse($payload);
                self::fail('Invalid payload accepted: ' . $payload);
            } catch (MonoWebhookException $exception) {
                self::assertInstanceOf(MonoException::class, $exception, '1.x catch (MonoException) still works');
            }
        }

        $this->expectException(MonoWebhookException::class);
        $webhooks->verify('{"a":1}', null);
    }

    public function testRepeatedClientCreationInOneProcessKeepsVerifying(): void
    {
        $this->requireOpenSsl();
        [$privateKey, $publicKey] = EcdsaKeys::pair();
        $body = '{"invoiceId":"p2_1","status":"success"}';
        $xSign = EcdsaKeys::sign($body, $privateKey);
        $keyResponse = json_encode(['key' => $publicKey], JSON_THROW_ON_ERROR);

        $first = new FakeHttpClient(new HttpResponse(200, $keyResponse));
        self::assertTrue(@(new MonoClient('token', self::BASE, $first))->webhooks()->verify($body, $xSign));

        // Queue workers build a client per job; the second one must not be rate limited.
        for ($job = 0; $job < 3; ++$job) {
            $http = new FakeHttpClient();
            self::assertTrue((new MonoClient('token', self::BASE, $http))->webhooks()->verify($body, $xSign));
            self::assertCount(0, $http->requests);
        }
    }

    private function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL is not installed.');
        }
    }
}
