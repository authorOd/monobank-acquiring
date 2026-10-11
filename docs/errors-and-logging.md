# Errors and Safe Logging

## Exceptions

The SDK throws structured exceptions:

- `MonoApiException` - non-2xx Monobank API response.
- `MonoTransportException` - cURL or network failure.
- `MonoJsonException` - JSON encode/decode failure.
- `MonoWebhookException` - invalid webhook signature, public key, or payload.
- `MonoPublicKeyUnavailableException` - a `MonoWebhookException` subclass: the
  Monobank public key could not be obtained, so the signature was not checked.
  Respond with a retryable status (503) instead of 400. See
  [Webhooks](webhooks.md#verify-and-parse).

```php
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Support\SensitiveData;

try {
    $invoice = $mono->invoices()->createInvoice($invoiceData);
} catch (MonoApiException $e) {
    $logger->error('Monobank API request failed', $e->getSafeContext() + [
        'response' => SensitiveData::sanitize($e->getResponseData()),
    ]);
}
```

`MonoApiException::getMessage()` contains only the status, method, and URL path,
so a default exception logger never records query values such as `cardToken`,
`walletId`, or a receipt `email`. `getSafeContext()` returns `status`, `method`,
`path`, `safe_url` (sensitive query values replaced with `[redacted]`),
`request_id`, `err_code`, and `retry_after`. `getUrl()` still returns the raw
request URL and must not be logged as-is; use `getSafeUrl()` or `getPath()`.

## Exception Traces

PHP stores call arguments in exception traces unless
`zend.exception_ignore_args=On`. That is the production `php.ini` default, but
not the built-in default, so containers without a `php.ini` keep arguments, and
error trackers such as Sentry upload them. Every SDK parameter that receives the
merchant token, the request URL or headers, a card token, a wallet id, an e-mail,
or a raw webhook body is marked `#[\SensitiveParameter]`. On PHP 8.2 and later
those values appear as `SensitiveParameterValue` objects in traces.

PHP 8.0 and 8.1 ignore the attribute. On those versions set
`zend.exception_ignore_args=On` in production. If you implement
`HttpClientInterface`, repeat `#[\SensitiveParameter]` on `$url`, `$headers`, and
`$body`, because PHP does not inherit parameter attributes.

## Safe Logging

Use `SensitiveData::sanitize()` before writing raw Monobank payloads or headers to
application logs.

```php
use Vladchornyi\Mono\Support\SensitiveData;

$logger->info('Monobank webhook received', [
    'headers' => SensitiveData::sanitizeHeaders($headers),
    'payload' => SensitiveData::sanitize($payload),
]);
```

To log selected diagnostic headers, pass them as explicit safe entries:

```php
use Vladchornyi\Mono\Support\SensitiveData;

$headers = [
    'Content-Type' => 'application/json',
    'X-Request-Id' => 'request-1',
    'X-Correlation-Id' => 'corr-1',
    'Traceparent' => '00-...',
];

$logger->info('Monobank webhook received', [
    'headers' => SensitiveData::sanitizeHeaders(
        $headers,
        ['X-Correlation-Id', 'Traceparent']
    ),
]);
```

Unknown headers are redacted by default. Caller-supplied safe headers are merged into
the built-in allowlist, and the SDK keeps redacting built-in sensitive names even if
they are repeated in `additionalSafeHeaders`.

For typed webhook payloads:

`MonoClient::webhooks()` fetches and caches the public key itself (inject a
shared `publicKeyCache` on multi-worker deployments); a trusted key may instead
be passed as the third argument. See [Webhooks](webhooks.md#verify-and-parse).

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);

$logger->info('Monobank webhook received', [
    'type' => $event->type(),
    'deduplicationKey' => $event->deduplicationKey(),
    'payload' => $event->safeArray(),
]);
```

The helpers fully redact values for sensitive keys such as `X-Sign`, `X-Token`,
cookies, API keys, client secrets, access/refresh tokens, `cardToken`, `walletId`,
`maskedPan`, `rrn`, `approvalCode`, and emails. `sanitizeHeaders()` uses a safe
allowlist and redacts unknown headers by default.
