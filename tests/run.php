<?php

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Support\SensitiveData;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

require __DIR__ . '/../vendor/autoload.php';

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?array}> */
    public array $requests = [];

    /** @var HttpResponse[] */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        return array_shift($this->responses) ?? new HttpResponse(200, '{}');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$discount = new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 1000);
$basketItem = new BasketOrderItem(
    name: 'Online course access',
    qty: 1,
    sum: 59000,
    code: 'SV-SUB-001',
    tax: [8],
    discounts: [$discount]
);
$merchantPaymInfo = new MerchantPaymInfoItem(
    reference: 'ORDER-1',
    destination: 'Оплата підписки',
    comment: 'Example Store order',
    customerEmails: ['student@example.com'],
    basketOrder: [$basketItem]
);
$invoiceData = new InvoiceData(
    amount: 58000,
    redirectUrl: 'https://example.com/payments/return',
    webHookUrl: 'https://example.com/webhooks/monobank/invoice',
    saveCardData: ['saveCard' => true],
    merchantPaymInfo: $merchantPaymInfo,
    ccy: 980,
    validity: 3600,
    paymentType: 'debit'
);

$payload = $invoiceData->toArray();
assert_same(58000, $payload['amount'], 'Invoice amount must stay in minor units');
assert_same(980, $payload['ccy'], 'Invoice currency must be serialized');
assert_same('debit', $payload['paymentType'], 'Invoice payment type must be serialized');
assert_true(!array_key_exists('qrId', $payload), 'Null invoice fields must be omitted');
assert_same(59000, $payload['merchantPaymInfo']['basketOrder'][0]['total'], 'Basket item total must be calculated');
assert_true(!array_key_exists('barcode', $payload['merchantPaymInfo']['basketOrder'][0]), 'Null basket fields must be omitted');

$fake = new FakeHttpClient(new HttpResponse(200, '{"status":"success"}'));
$client = new MonoClient('secret-token', 'https://example.test/api/merchant', $fake);
$status = $client->invoices()->getInvoiceStatus('p2_hello world');
assert_same('success', $status['status'], 'Invoice status response must decode JSON');
assert_same(
    'https://example.test/api/merchant/invoice/status?invoiceId=p2_hello+world',
    $fake->requests[0]['url'],
    'Query parameters must be encoded safely'
);
assert_same('secret-token', $fake->requests[0]['headers']['X-Token'], 'X-Token header must be sent');

$fakeError = new FakeHttpClient(new HttpResponse(429, '{"error":"Too many requests"}'));
$clientWithError = new MonoClient('secret-token', 'https://example.test/api/merchant', $fakeError);
try {
    $clientWithError->invoices()->getInvoiceStatus('p2_rate_limited');
    throw new RuntimeException('MonoApiException was not thrown');
} catch (MonoApiException $e) {
    assert_same(429, $e->getStatusCode(), 'API exception must expose HTTP status');
    assert_same(['error' => 'Too many requests'], $e->getResponseData(), 'API exception must expose decoded body');
}

$payloadJson = '{"invoiceId":"p2_9ZgpZVsl3","status":"success","modifiedDate":"2026-07-01T10:00:00+03:00"}';
$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
assert_true($key !== false, 'OpenSSL must be able to create an EC key');
openssl_sign($payloadJson, $signature, $key, OPENSSL_ALGO_SHA256);
$details = openssl_pkey_get_details($key);
assert_true(is_array($details) && isset($details['key']), 'OpenSSL public key must be available');

$verifier = new WebhookVerifier();
assert_true(
    $verifier->verify($payloadJson, base64_encode($signature), base64_encode($details['key'])),
    'Webhook verifier must accept a valid ECDSA signature'
);

$pubkeyFake = new FakeHttpClient(new HttpResponse(200, json_encode(['key' => base64_encode($details['key'])])));
$webhooks = (new MonoClient('secret-token', 'https://example.test/api/merchant', $pubkeyFake))->webhooks();
$parsed = $webhooks->parseVerified($payloadJson, base64_encode($signature));
assert_same('success', $parsed['status'], 'Webhook service must parse verified payloads');
$invoiceEvent = $webhooks->parseVerifiedPayload($payloadJson, base64_encode($signature), base64_encode($details['key']));
assert_same(WebhookPayload::TYPE_INVOICE, $invoiceEvent->type(), 'Invoice payload type must be detected');
assert_same('p2_9ZgpZVsl3', $invoiceEvent->invoiceId(), 'Invoice id accessor must work');
assert_true($invoiceEvent->isSuccessful(), 'Successful invoice status must be detected');
assert_true(
    $webhooks->shouldApply($parsed, new \DateTimeImmutable('2026-07-01T09:59:59+03:00')),
    'Webhook event with newer modifiedDate must be applicable'
);
assert_true(
    !$webhooks->shouldApply($parsed, new \DateTimeImmutable('2026-07-01T10:00:00+03:00')),
    'Webhook event with same modifiedDate must be treated as stale'
);
assert_true(
    $webhooks->shouldApplyPayload($invoiceEvent, new \DateTimeImmutable('2026-07-01T09:59:59+03:00')),
    'Typed webhook event must be applicable with newer modifiedDate'
);

$subscriptionStatusEvent = WebhookPayload::fromArray([
    'subscriptionId' => 'sub_123',
    'status' => 'active',
    'startDate' => '2026-07-01T10:00:00Z',
    'amount' => 59000,
    'ccy' => 980,
    'interval' => '1m',
    'nextChargeDate' => '2026-08-01T10:00:00Z',
    'summary' => [
        'totalPaid' => 1,
        'totalFailed' => 0,
    ],
    'walletData' => [
        'walletId' => 'wallet-1234567890',
        'cardToken' => 'card-token-1234567890',
        'status' => 'created',
    ],
]);
assert_same(WebhookPayload::TYPE_SUBSCRIPTION_STATUS, $subscriptionStatusEvent->type(), 'Subscription status type must be detected');
assert_same('sub_123', $subscriptionStatusEvent->subscriptionId(), 'Subscription id accessor must work');
assert_same(1, $subscriptionStatusEvent->totalPaid(), 'summary.totalPaid accessor must work');
assert_same('1m', $subscriptionStatusEvent->interval(), 'interval accessor must work');
assert_true($subscriptionStatusEvent->nextChargeDate() instanceof \DateTimeImmutable, 'nextChargeDate accessor must parse dates');
assert_true(
    $subscriptionStatusEvent->safeArray()['walletData']['walletId'] !== 'wallet-1234567890',
    'Typed payload safeArray must mask wallet id'
);

$subscriptionChargeEvent = WebhookPayload::fromArray([
    'subscriptionId' => 'sub_123',
    'invoiceId' => 'p2_charge_123',
    'status' => 'failure',
    'amount' => 59000,
    'ccy' => 980,
    'finalAmount' => 0,
    'failureReason' => 'Insufficient funds',
    'createdDate' => '2026-07-01T10:00:00Z',
    'modifiedDate' => '2026-07-01T10:01:00Z',
    'paymentInfo' => [
        'maskedPan' => '444403******1902',
        'rrn' => '123456789012',
    ],
]);
assert_same(WebhookPayload::TYPE_SUBSCRIPTION_CHARGE, $subscriptionChargeEvent->type(), 'Subscription charge type must be detected');
assert_same('p2_charge_123', $subscriptionChargeEvent->invoiceId(), 'Charge invoice id accessor must work');
assert_true($subscriptionChargeEvent->isFailure(), 'Failure charge status must be detected');
assert_same('subscription_charge:p2_charge_123', $subscriptionChargeEvent->eventKey(), 'Event key must use invoice id when available');

$sanitized = SensitiveData::sanitize([
    'X-Sign' => 'abcdefghijklmnopqrstuvwxyz',
    'nested' => ['cardToken' => '1234567890abcdef'],
]);
assert_same('abcd******************wxyz', $sanitized['X-Sign'], 'Sensitive headers must be masked');
assert_same('1234********cdef', $sanitized['nested']['cardToken'], 'Nested sensitive values must be masked');

echo "All tests passed\n";
