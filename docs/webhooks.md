# Webhooks

Monobank sends webhook signatures in the `X-Sign` header. Verify the raw request
body before parsing JSON or applying side effects.

## Verify and Parse

```php
$rawBody = file_get_contents('php://input');
$xSign = $_SERVER['HTTP_X_SIGN'] ?? null;

try {
    $payload = $mono->webhooks()->parseVerified($rawBody, $xSign);
} catch (\Vladchornyi\Mono\Exceptions\MonoWebhookException $e) {
    http_response_code(400);
    exit;
}
```

## Typed Payload Helper

Use `parseVerifiedPayload()` when you want convenient accessors instead of raw
array lookups.

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign, $cachedPublicKey);

echo $event->type();            // invoice, subscription_status, subscription_charge
echo $event->eventKey();        // stable key for logs/deduplication
echo $event->invoiceId();
echo $event->subscriptionId();
echo $event->status();
echo $event->amount();
echo $event->ccy();
echo $event->modifiedDate()?->format(DATE_ATOM);

$safeForLogs = $event->safeArray();
```

Payload types are inferred from identifiers:

- `invoiceId` only: `invoice`.
- `subscriptionId` only: `subscription_status`.
- `subscriptionId` plus `invoiceId`: `subscription_charge`.

## Cache the Public Key

For production apps, cache the public key and refresh it only when verification
fails.

```php
$webhooks = $mono->webhooks();

try {
    $payload = $webhooks->parseVerified($rawBody, $xSign, $cachedPublicKey);
} catch (\Vladchornyi\Mono\Exceptions\MonoWebhookException $e) {
    $freshPublicKey = $webhooks->getPublicKey();
    $payload = $webhooks->parseVerified($rawBody, $xSign, $freshPublicKey);
}
```

## Reject Stale Events

Webhook events are not guaranteed to arrive in chronological order. Store
`modifiedDate` locally and apply only newer events.

```php
if (!$mono->webhooks()->shouldApply($payload, $storedModifiedDate)) {
    return ['message' => 'stale'];
}
```

With the typed helper:

```php
if (!$mono->webhooks()->shouldApplyPayload($event, $storedModifiedDate)) {
    return ['message' => 'stale'];
}
```

Recommended invoice webhook flow:

1. Read the raw request body.
2. Verify `X-Sign`.
3. Parse JSON.
4. Find the local invoice by `invoiceId`.
5. Compare payload `modifiedDate` with the stored event timestamp.
6. Apply state changes idempotently.
7. Return HTTP 200 for duplicate or stale events.

## Common Payload Shapes

Invoice status events usually follow the same shape as the invoice status API:

```json
{
  "invoiceId": "p2_example",
  "status": "success",
  "failureReason": null,
  "errCode": null,
  "amount": 58000,
  "ccy": 980,
  "finalAmount": 58000,
  "createdDate": "2026-07-01T10:00:00Z",
  "modifiedDate": "2026-07-01T10:02:00Z",
  "reference": "ORDER-1001",
  "destination": "Online course access",
  "paymentInfo": {
    "maskedPan": "444403******1902",
    "approvalCode": "123456",
    "rrn": "123456789012",
    "paymentSystem": "visa",
    "paymentMethod": "pan",
    "fee": 0
  },
  "walletData": {
    "walletId": "wallet-id",
    "cardToken": "card-token",
    "status": "new"
  }
}
```

Subscription status events commonly include lifecycle and aggregate fields:

```json
{
  "subscriptionId": "sub_example",
  "status": "active",
  "startDate": "2026-07-01T10:00:00Z",
  "endDate": null,
  "amount": 59000,
  "ccy": 980,
  "interval": "1m",
  "nextChargeDate": "2026-08-01T10:00:00Z",
  "cancellationDesc": null,
  "summary": {
    "totalPaid": 1,
    "totalFailed": 0
  },
  "walletData": {
    "walletId": "wallet-id",
    "cardToken": "card-token",
    "status": "created"
  }
}
```

Subscription charge events should be handled like invoice events scoped to a
subscription:

```json
{
  "subscriptionId": "sub_example",
  "invoiceId": "p2_charge_example",
  "status": "success",
  "amount": 59000,
  "ccy": 980,
  "finalAmount": 59000,
  "payMethod": "wallet",
  "failureReason": null,
  "createdDate": "2026-07-01T10:00:00Z",
  "modifiedDate": "2026-07-01T10:02:00Z",
  "paymentInfo": {
    "maskedPan": "444403******1902",
    "approvalCode": "123456",
    "rrn": "123456789012"
  }
}
```

Always treat `walletData`, `paymentInfo`, `X-Sign`, and `X-Token` as sensitive
logging data. Use `WebhookPayload::safeArray()` or `SensitiveData::sanitize()`.
