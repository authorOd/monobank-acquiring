<?php

namespace Vladchornyi\Mono\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\Models\SaveCardData;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\Models\WalletPaymentData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;
use Vladchornyi\Mono\Tests\Support\OpenApiSpec;
use Vladchornyi\Mono\Webhooks\WebhookPayload;

/**
 * Every SDK request is checked against the Monobank OpenAPI fixture: the
 * method and path must exist, the encoded JSON body must contain only
 * documented fields (as JSON lists where the schema says array) and all
 * required ones, and query parameters must be documented. Responses built from
 * the specification's own examples must be readable by the SDK helpers.
 */
final class OpenApiContractTest extends TestCase
{
    private const BASE = 'https://example.test/api/merchant';
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Fields the SDK sends that the v2412 document no longer lists. Each entry
     * must be justified here and in docs/api-coverage.md; the test fails on any
     * other undocumented field.
     */
    private const KNOWN_UNDOCUMENTED_REQUEST_FIELDS = [
        // Present in the v2410 document and in 1.x; v2412 documents neither this field nor `tipsInfo`.
        'undocumented field body.tipsEmployeeId',
    ];

    private OpenApiSpec $spec;

    protected function setUp(): void
    {
        $this->spec = OpenApiSpec::load();
    }

    /**
     * @dataProvider requestProvider
     * @param callable(MonoClient): mixed $call
     */
    public function testRequestMatchesTheSpecification(callable $call, string $method, string $path): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'));
        $call(new MonoClient('token', self::BASE, $http));

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        $parts = parse_url($request['url']);
        self::assertIsArray($parts);
        $sentPath = str_replace('/api/merchant', '/api/merchant', (string) ($parts['path'] ?? ''));

        self::assertSame($method, $request['method']);
        self::assertSame($path, $sentPath);
        self::assertContains($method, $this->spec->operations()[$path] ?? [], "{$method} {$path} not in spec");

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $documented = $this->spec->queryParameters($method, $path);

        foreach (array_keys($query) as $name) {
            self::assertArrayHasKey((string) $name, $documented, "undocumented query parameter {$name}");
        }

        foreach ($documented as $name => $info) {
            if ($info['required']) {
                self::assertArrayHasKey($name, $query, "missing required query parameter {$name}");
            }
        }

        $schema = $this->spec->requestSchema($method, $path);

        if ($schema === null) {
            self::assertNull($request['body'], 'spec has no request body');

            return;
        }

        self::assertIsArray($request['body']);
        // Validate the exact wire bytes the transport would send, objects and lists kept distinct.
        $wire = json_encode($request['body'], self::ENCODE_FLAGS);
        $problems = array_values(array_diff(
            $this->spec->jsonProblems($wire, $schema),
            self::KNOWN_UNDOCUMENTED_REQUEST_FIELDS
        ));
        self::assertSame([], $problems, 'wire body deviates from the spec');
    }

    /** @return array<string, array{callable(MonoClient): mixed, string, string}> */
    public function requestProvider(): array
    {
        $fullInvoice = static fn (MonoClient $mono) => $mono->invoices()->createInvoice(new InvoiceData(
            amount: 4200,
            redirectUrl: 'https://example.com/return',
            webHookUrl: 'https://example.com/hook',
            saveCardData: new SaveCardData(true, 'wallet-1'),
            merchantPaymInfo: new MerchantPaymInfoItem(
                reference: 'ORDER-1',
                destination: 'Покупка щастя',
                comment: 'note',
                customerEmails: ['a@example.com'],
                discounts: [new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_PERCENT, 5, tax: [8])],
                basketOrder: [new BasketOrderItem(
                    name: 'Табуретка',
                    qty: 2,
                    sum: 2100,
                    code: 'd21da1c47f3c45fca10a10c32518bdeb',
                    icon: 'https://example.com/i.png',
                    unit: 'шт.',
                    barcode: '123',
                    header: 'h',
                    footer: 'f',
                    tax: [8],
                    uktzed: 'uktzedcode',
                    discounts: [new DiscountItem(DiscountItem::TYPE_EXTRA_CHARGE, DiscountItem::MODE_VALUE, 100)],
                    splitReceiverId: 'receiver-1'
                )],
                metadata: ['key1' => 'value1']
            ),
            ccy: 980,
            validity: 3600,
            paymentType: 'hold',
            code: '0a8637b3bccb42aa93fdeb791b8b58e9',
            agentFeePercent: 1.42,
            tipsEmployeeId: 'emp-1',
            successUrl: 'https://example.com/ok',
            failUrl: 'https://example.com/fail',
            displayType: 'iframe',
            withAppUrl: true
        ));

        return [
            'invoice create, every field' => [$fullInvoice, 'POST', '/api/merchant/invoice/create'],
            'invoice create, QR cash register' => [
                static fn (MonoClient $m) => $m->invoices()->createInvoice(new InvoiceData(
                    amount: 4200,
                    qrId: 'XJ_DiM4rTd5V'
                )),
                'POST',
                '/api/merchant/invoice/create',
            ],
            'invoice create, verification' => [
                static fn (MonoClient $m) => $m->invoices()->createInvoice(new InvoiceData(
                    amount: 0,
                    paymentType: 'verification',
                    saveCardData: new SaveCardData(true)
                )),
                'POST',
                '/api/merchant/invoice/create',
            ],
            'invoice status' => [
                static fn (MonoClient $m) => $m->invoices()->getInvoiceStatus('p2_9ZgpZVsl3'),
                'GET',
                '/api/merchant/invoice/status',
            ],
            'invoice cancel with items' => [
                static fn (MonoClient $m) => $m->invoices()->cancelInvoice('p2_9ZgpZVsl3', 'ext', 5000, [
                    ['name' => 'Табуретка', 'qty' => 2, 'sum' => 2100, 'code' => 'c', 'tax' => [8]],
                ]),
                'POST',
                '/api/merchant/invoice/cancel',
            ],
            'invoice remove' => [
                static fn (MonoClient $m) => $m->invoices()->removeInvoice('p2_9ZgpZVsl3'),
                'POST',
                '/api/merchant/invoice/remove',
            ],
            'invoice finalize' => [
                static fn (MonoClient $m) => $m->invoices()->finalizeHold('p2_9ZgpZVsl3', 4200, [
                    ['name' => 'Табуретка', 'qty' => 1, 'sum' => 4200, 'code' => 'c'],
                ]),
                'POST',
                '/api/merchant/invoice/finalize',
            ],
            'invoice receipt' => [
                static fn (MonoClient $m) => $m->invoices()->getReceipt('p2_9ZgpZVsl3', 'a@example.com'),
                'GET',
                '/api/merchant/invoice/receipt',
            ],
            'invoice fiscal checks' => [
                static fn (MonoClient $m) => $m->invoices()->getFiscalChecks('p2_9ZgpZVsl3'),
                'GET',
                '/api/merchant/invoice/fiscal-checks',
            ],
            'merchant details' => [
                static fn (MonoClient $m) => $m->merchant()->getDetails(),
                'GET',
                '/api/merchant/details',
            ],
            'submerchant list' => [
                static fn (MonoClient $m) => $m->merchant()->getSubmerchants(),
                'GET',
                '/api/merchant/submerchant/list',
            ],
            'split receiver list' => [
                static fn (MonoClient $m) => $m->merchant()->getSplitReceivers(),
                'GET',
                '/api/merchant/split-receiver/list',
            ],
            'statement' => [
                static fn (MonoClient $m) => $m->statements()->getStatement(1700000000, 1700003600, 'code'),
                'GET',
                '/api/merchant/statement',
            ],
            'pubkey' => [static fn (MonoClient $m) => $m->pubkey()->get(), 'GET', '/api/merchant/pubkey'],
            'wallet cards' => [
                static fn (MonoClient $m) => $m->wallet()->getCards('w1'),
                'GET',
                '/api/merchant/wallet',
            ],
            'wallet payment' => [
                static fn (MonoClient $m) => $m->wallet()->payByToken(new WalletPaymentData(
                    cardToken: 'tok',
                    amount: 100,
                    ccy: 980,
                    initiationKind: 'merchant',
                    redirectUrl: 'https://example.com/r',
                    webHookUrl: 'https://example.com/h',
                    merchantPaymInfo: new MerchantPaymInfoItem(reference: 'r'),
                    paymentType: 'debit'
                )),
                'POST',
                '/api/merchant/wallet/payment',
            ],
            'wallet delete card' => [
                static fn (MonoClient $m) => $m->wallet()->deleteCard('tok'),
                'DELETE',
                '/api/merchant/wallet/card',
            ],
            'subscription create' => [
                static fn (MonoClient $m) => $m->subscriptions()->createSubscription(new SubscriptionData(
                    100,
                    980,
                    'https://example.com/r',
                    ['chargeUrl' => 'https://example.com/c', 'statusUrl' => 'https://example.com/s'],
                    '2w',
                    3600
                )),
                'POST',
                '/api/merchant/subscription/create',
            ],
            'subscription status' => [
                static fn (MonoClient $m) => $m->subscriptions()->getSubscriptionStatus('s1'),
                'GET',
                '/api/merchant/subscription/status',
            ],
            'subscription payments' => [
                static fn (MonoClient $m) => $m->subscriptions()->getSubscriptionPayments(
                    's1',
                    '2026-07-01T10:00:00+03:00',
                    '2026-07-02T10:00:00+03:00',
                    10,
                    2
                ),
                'GET',
                '/api/merchant/subscription/payments',
            ],
            'subscription list' => [
                static fn (MonoClient $m) => $m->subscriptions()->getSubscriptionList(
                    '2026-07-01T10:00:00+03:00',
                    null,
                    'cancelled'
                ),
                'GET',
                '/api/merchant/subscription/list',
            ],
            'subscription payments iterator' => [
                static fn (MonoClient $m) => iterator_to_array($m->subscriptions()->iterateSubscriptionPayments(
                    's1',
                    '2026-07-01T10:00:00+03:00',
                    '2026-07-02T10:00:00+03:00',
                    50
                )),
                'GET',
                '/api/merchant/subscription/payments',
            ],
            'subscription list iterator' => [
                static fn (MonoClient $m) => iterator_to_array($m->subscriptions()->iterateSubscriptionList(
                    '2026-07-01T10:00:00+03:00',
                    status: 'active',
                    limit: 50
                )),
                'GET',
                '/api/merchant/subscription/list',
            ],
            'subscription remove' => [
                static fn (MonoClient $m) => $m->subscriptions()->removeSubscription('s1'),
                'POST',
                '/api/merchant/subscription/remove',
            ],
            'subscription cancel' => [
                static fn (MonoClient $m) => $m->subscriptions()->cancelSubscription('s1', 50),
                'POST',
                '/api/merchant/subscription/edit',
            ],
        ];
    }

    public function testInvoiceStatusExampleIsFullyReadableAsAWebhookPayload(): void
    {
        $schema = $this->spec->responseSchema('GET', '/api/merchant/invoice/status');
        self::assertNotNull($schema);
        $example = (array) $this->spec->example($schema);
        $example['status'] = 'success';

        $event = WebhookPayload::fromArray($example);

        self::assertSame(WebhookPayload::TYPE_INVOICE, $event->type());
        self::assertSame($example['invoiceId'], $event->invoiceId());
        self::assertTrue($event->isSuccessful());
        self::assertSame($example['amount'], $event->amount());
        self::assertSame($example['ccy'], $event->ccy());
        self::assertSame($example['finalAmount'], $event->finalAmount());
        self::assertSame($example['errCode'], $event->errCode());
        self::assertSame($example['failureReason'], $event->failureReason());
        self::assertSame($example['paymentInfo'], $event->paymentInfo());
        self::assertSame($example['walletData'], $event->walletData());
        self::assertSame('123456789', substr((string) $event->modifiedInstant(), -10, 9));
        self::assertNotNull($event->createdDate());
        self::assertStringStartsWith('evt:v2:', $event->deduplicationKey());

        foreach ($schema['properties']['status']['enum'] as $status) {
            $example['status'] = $status;
            self::assertTrue(WebhookPayload::fromArray($example)->isInvoiceStatus(), $status);
        }
    }

    public function testSubscriptionExamplesAreReadable(): void
    {
        $status = (array) $this->spec->example(
            (array) $this->spec->responseSchema('GET', '/api/merchant/subscription/status')
        );
        $event = WebhookPayload::fromArray($status);

        self::assertSame(WebhookPayload::TYPE_SUBSCRIPTION_STATUS, $event->type());
        self::assertTrue($event->isSubscriptionStatus());
        self::assertContains($event->status(), WebhookPayload::SUBSCRIPTION_STATUSES);
        self::assertSame($status['summary']['totalPaid'], $event->totalPaid());
        self::assertSame($status['walletData'], $event->walletData());
        self::assertNotNull($event->nextChargeDate());

        $payments = (array) $this->spec->example(
            (array) $this->spec->responseSchema('GET', '/api/merchant/subscription/payments')
        );
        self::assertContains($payments['payments'][0]['status'], WebhookPayload::SUBSCRIPTION_PAYMENT_STATUSES);
    }

    public function testSubscriptionStatusVocabularyMatchesTheSpecification(): void
    {
        $status = $this->spec->responseSchema('GET', '/api/merchant/subscription/status');
        $payments = $this->spec->responseSchema('GET', '/api/merchant/subscription/payments');
        self::assertNotNull($status);
        self::assertNotNull($payments);

        foreach ($status['properties']['status']['enum'] as $documented) {
            self::assertContains($documented, WebhookPayload::SUBSCRIPTION_STATUSES);
        }

        $paymentItem = $this->spec->resolve($payments['properties']['payments']['items']);
        $paymentStatuses = $paymentItem['properties']['status']['enum'];
        self::assertSame($paymentStatuses, WebhookPayload::SUBSCRIPTION_PAYMENT_STATUSES);
    }

    public function testDocumentedSignatureAndKeyFormatsAreAccepted(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL is not installed.');
        }

        $fixture = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/fixtures/monobank/webhook-signature-format.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $verifier = new \Vladchornyi\Mono\Webhooks\WebhookVerifier();

        $verifier->assertSignature($fixture['xSignBase64']);   // canonical DER check must accept real signatures
        $verifier->assertPublicKey($fixture['publicKeyBase64']);
        self::assertFalse($verifier->verify($fixture['body'], $fixture['xSignBase64'], $fixture['publicKeyBase64']));
        self::assertSame(71, strlen((string) base64_decode($fixture['xSignBase64'], true)));
    }
}
