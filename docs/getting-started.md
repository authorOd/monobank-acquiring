# Getting Started: First Payment

This page takes you from a token to a verified payment with the smallest
possible code. Everything else (fiscalization, tokenization, holds, splits) is
an add-on documented in [Invoices](invoices.md).

## 1. Token

Use a live token from <https://web.monobank.ua/> or a test token from
<https://api.monobank.ua/>. The test environment accepts any card number that
passes the Luhn check and never authorizes real money.

```php
use Vladchornyi\Mono\MonoClient;

$mono = new MonoClient($_ENV['MONO_KEY']);
```

## 2. Create a Minimal Invoice

Only `amount` is required. Add `redirectUrl` so the customer returns to your
site and `webHookUrl` so your backend learns the result without polling.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,                                          // 580.00 UAH in kopecks
    redirectUrl: 'https://example.com/payments/return',
    webHookUrl: 'https://example.com/webhooks/monobank'
));

// Persist $invoice['invoiceId'] with your order, then send the customer here:
header('Location: ' . $invoice['pageUrl']);
```

## 3. Receive the Verified Webhook

Monobank POSTs the invoice status to `webHookUrl` on every change except
`expired`, signed in the `X-Sign` header. Verify the raw body before touching
it. `MonoClient::webhooks()` fetches and caches the public key for you.

```php
use Vladchornyi\Mono\Exceptions\MonoPublicKeyUnavailableException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;

$rawBody = file_get_contents('php://input');
$xSign = $_SERVER['HTTP_X_SIGN'] ?? null;

try {
    $event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);
} catch (MonoPublicKeyUnavailableException $e) {
    error_log('Monobank webhook deferred: ' . $e->getMessage());
    http_response_code(503);                                // key temporarily unavailable: retry
    exit;
} catch (MonoWebhookException $e) {
    http_response_code(400);                                // bad signature: do not retry
    exit;
}

$order = $orders->findByInvoiceId($event->invoiceId());

if ($order === null || !$mono->webhooks()->shouldApplyPayload($event, $order->modifiedDate)) {
    http_response_code(200);                                // unknown or stale: acknowledge
    exit;
}

if ($event->isSuccessful()) {
    $orders->markPaid($order, $event->finalAmount(), $event->deduplicationKey());
} elseif ($event->isFailure()) {
    $orders->markFailed($order, $event->status(), $event->errCode());
}

if ($event->walletData() !== []) {
    $orders->storeCardToken($order, $event->walletData());     // tokenization update, separate from status
}

if ($event->modifiedInstant() !== null) {
    $orders->storeModifiedDate($order, $event->modifiedInstant()->toRfc3339());   // never overwrite with null
}

http_response_code(200);
```

Catch `MonoPublicKeyUnavailableException` before its parent
`MonoWebhookException`. It means the signature could not be checked yet because
the Monobank public key was temporarily unavailable, so the delivery must be
retried rather than rejected.

Three rules keep this correct: apply each `deduplicationKey()` once, persist the
state change before responding 200, and only write fields the event actually
carries. A tokenization callback has `walletData` but often no `status` or
`modifiedDate`; clearing the stored date on such an event would let an older
`processing` delivery overwrite a `success`. The production-grade version of
this handler, with all three rules inside one database transaction, is in
[`examples/Webhooks/`](../examples/Webhooks/) and explained in
[Webhooks](webhooks.md#reference-handler-one-transaction-then-an-outbox).

## 4. Fallback: Poll the Status

Use polling only when a webhook did not arrive (for example after `validity`
elapsed, because `expired` is never sent as a webhook):

```php
$status = $mono->invoices()->getInvoiceStatus($invoiceId);

if ($status['status'] === 'success') {
    // paid
}
```

## 5. Next Steps

- [Invoices](invoices.md): holds, refunds, fiscalization, tokenization, split
  payments, receipts.
- [Subscriptions](subscriptions.md): recurring payments.
- [Webhooks](webhooks.md): shared public-key cache for multi-worker deployments.
- [API coverage](api-coverage.md): what the SDK implements and what it does not.
