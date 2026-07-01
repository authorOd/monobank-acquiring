# Errors and Safe Logging

## Exceptions

The SDK throws structured exceptions:

- `MonoApiException` - non-2xx Monobank API response.
- `MonoTransportException` - cURL or network failure.
- `MonoJsonException` - JSON encode/decode failure.
- `MonoWebhookException` - invalid webhook signature, public key, or payload.

```php
use Vladchornyi\Mono\Exceptions\MonoApiException;

try {
    $invoice = $mono->invoices()->createInvoice($invoiceData);
} catch (MonoApiException $e) {
    report([
        'status' => $e->getStatusCode(),
        'body' => $e->getResponseData(),
    ]);
}
```

## Safe Logging

Use `SensitiveData::sanitize()` before writing raw Monobank payloads or headers to
application logs.

```php
use Vladchornyi\Mono\Support\SensitiveData;

$logger->info('Monobank webhook received', SensitiveData::sanitize([
    'headers' => $headers,
    'payload' => $payload,
]));
```

For typed webhook payloads:

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign, $cachedPublicKey);

$logger->info('Monobank webhook received', [
    'type' => $event->type(),
    'eventKey' => $event->eventKey(),
    'payload' => $event->safeArray(),
]);
```

The helper masks values for sensitive keys such as `X-Sign`, `X-Token`,
`cardToken`, `walletId`, `maskedPan`, `rrn`, `approvalCode`, emails, and tokens.
