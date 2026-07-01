# Changelog

## 1.4.0 - 2026-07-01

### Added

- Replaceable HTTP layer via `HttpClientInterface` and default `CurlHttpClient`.
- Structured exceptions: `MonoApiException`, `MonoTransportException`,
  `MonoJsonException`, and `MonoWebhookException`.
- `WebhookService` and `WebhookVerifier` for `X-Sign` verification, webhook JSON
  parsing, public-key fetching, and `modifiedDate` stale-event checks.
- `WebhookPayload` helper with typed accessors, event type detection, event keys,
  status helpers, and sanitized payload output.
- `SensitiveData::sanitize()` helper for safer application logging.
- Expanded `InvoiceData` support for `ccy`, `validity`, `paymentType`, `qrId`,
  `code`, `agentFeePercent`, and `tipsEmployeeId`.
- Invoice operations for invalidation and hold finalization.
- Merchant details endpoint.
- Statement `code` parameter support for submerchant statements.
- Self-contained regression tests runnable with `composer test`.

### Changed

- Service methods now return typed arrays and build query strings with
  `http_build_query`.
- Request JSON uses `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_UNICODE`, and
  `JSON_PRESERVE_ZERO_FRACTION`.
- Payload serializers omit `null` values from nested fiscalization models.
- Composer metadata now declares the real runtime extensions and MIT license.

### Compatibility

- Existing v1.x calls such as `new MonoClient($key)`,
  `invoices()->createInvoice()`, `getInvoiceStatus()`, `subscriptions()`, and the
  current model constructors remain supported.
