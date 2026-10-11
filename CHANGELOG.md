# Changelog

## 2.1.0 - 2026-10-12

### Security

- Mark every parameter that receives the merchant token, request URL or headers,
  card token, wallet id, e-mail, or raw webhook body as `#[\SensitiveParameter]`,
  so PHP 8.2+ keeps these values out of exception traces and error trackers.
- Require HTTPS for the API `baseUrl`; plain HTTP is accepted only for loopback
  hosts, so a misconfigured URL can no longer send `X-Token` in clear text.
- Redact completed outbox jobs in the webhook example so card tokens and wallet
  ids do not stay in `webhook_outbox`.
- Keep the webhook demo database out of the example directory: `bootstrap.php`
  reads `MONO_WEBHOOK_DSN` and defaults to the system temp directory.
- Pin GitHub Actions to commit SHAs and let Dependabot update them.

### Added

- `MonoPublicKeyUnavailableException` (extends `MonoWebhookException`) for a
  webhook whose signature could not be checked because no validated public key
  was available.
- `SubscriptionService::iterateSubscriptionList()` and
  `iterateSubscriptionPayments()` walk every page lazily and treat a missing
  `list` as an empty page.
- `InputValidator::secureBaseUrl()`.
- `examples/Webhooks/migrate.php` and `examples/Webhooks/bootstrap.php`.

### Fixed

- Genuine webhooks were answered with 400 when the shared cache was down or a
  key refresh was rate limited, because those cases threw the same exception as
  a forged signature. They now throw `MonoPublicKeyUnavailableException`, and
  the docs and example answer 503 and log the cause.
- A shared-cache outage no longer registers a process-local refresh guard that
  made every later verification sleep while polling, and its error message no
  longer claims a rate limit.
- `CurlHttpClient::__destruct()` no longer calls `curl_close()`, which emits a
  deprecation on PHP 8.5 and has been a no-op since PHP 8.0.
- The webhook example logs rejected deliveries, answers 503 without `MONO_KEY`,
  and no longer runs the schema on every request.

### Changed

- **Behavior change:** `MonoClient` and services throw
  `InvalidArgumentException` for a plain-HTTP `baseUrl` on a non-loopback host.
  The default Monobank URL is HTTPS and unaffected.
- The Composer package no longer ships `tests/`, `phpunit.xml.dist`,
  `phpcs.xml`, `phpstan.neon`, `.gitattributes`, or `.gitignore`.

## 2.0.0 - 2026-09-06

### Breaking

- Replace `WebhookPayload::eventKey()` with versioned `deduplicationKey()`
  values (`evt:v2:<hash>`) and `entityKey()` for entity lookup; `eventKey()`
  remains as a deprecated alias of `entityKey()` until 3.0.
- `SubscriptionService::cancelSubscription()` now cancels through
  `/subscription/edit` (`action=cancel`, optional `refundAmount`);
  `removeSubscription()` invalidates an uncharged subscription via
  `/subscription/remove`.
- `DiscountItem::$tax` is a list; items may be integer tax codes or tax objects
  because the Monobank schema leaves `discounts[].tax` items untyped.
- `WebhookService::create($publicKeyProvider, $verifier)` is the 2.0 way to build
  the service; the constructor keeps the 1.x `(pubkeyService, verifier)` names
  and order (deprecated, removed in 3.0) plus an optional `publicKeyProvider`, so
  positional, named, and mixed 1.x calls keep working.
- Reject malformed HTTP methods, JSON bodies on GET/HEAD, unsafe URLs, and
  invalid cache keys.

### Fixed

- Reject discount values with fractional cents (step `0.01`), normalize binary
  floating-point noise, and remove duplicate receipt e-mails before sending.
- Keep blocked outbox successors out of the batch limit so an exhausted or
  leased job cannot starve unrelated entities; refill batches without exceeding
  the run limit. Use an atomic self-join for MySQL leasing and separate,
  idempotent schemas for SQLite, MySQL/MariaDB, and PostgreSQL.
- Make the example copy commands portable to macOS and Linux, and execute the
  documented shell commands in the Composer consumer test.
- Check `multipleOf` and `uniqueItems` in the OpenAPI contract validator.
- Send `Content-Type: application/json` as a valid associative HTTP header.
- Support card-verification invoices (`verification`, zero amount, and required
  tokenization) and current invoice fields such as separate redirects, iframe
  display, and application deep links.
- Accept the documented recurring-payment interval grammar and ISO 4217 numeric
  currency codes.
- Keep `finalizeHold()` `amount` required, matching the Monobank schema.
- Re-index `basketOrder`, `discounts`, `customerEmails`, `tax`, and cancel/finalize
  `items` before encoding so sparse PHP arrays never serialize as JSON objects, and
  omit empty `metadata` instead of sending a JSON list.
- Clamp `validity` to 30 days instead of throwing, matching Monobank.
- Parse RFC3339Nano timestamps (up to nine fractional digits) emitted by the
  Monobank backend instead of rejecting them; reject timezone offsets outside
  `±23:59` that PHP would otherwise normalise.
- Apply webhook payloads without `modifiedDate` by default; the field is optional
  in the Monobank schema. `allowMissingModifiedDate: false` now rejects such
  payloads regardless of stored state.
- Order webhook events with nanosecond precision (`Instant`,
  `WebhookPayload::modifiedInstant()`, `WebhookService::getModifiedInstant()`;
  `shouldApply()` accepts the raw stored string or an `Instant`). A stored
  `DateTimeInterface` keeps the 1.x microsecond comparison.
- Make webhook deduplication keys unique per canonical payload while preserving a
  separate entity key for invoice/subscription grouping.
- Parse webhook dates and financial integers strictly.
- Prevent public mutable fiscalization models from bypassing validation during
  serialization.
- Align malformed webhook JSON with the `MonoWebhookException` contract.
- Fully redact sensitive values and recognize common key aliases.
- Keep query values (card tokens, wallet ids, receipt e-mails) out of
  `MonoApiException::getMessage()`; add `getPath()`, `getSafeUrl()`,
  `getErrorCode()`, `getSafeContext()`, and `SensitiveData::sanitizeUrl()`.
- Do not catch non-`Exception` cache errors in webhook public-key provider internals;
  only operational cache failures reported as `Exception` keep fail-closed fallback.
- Prevent cross-worker public-key refresh stampedes and stale-owner ABA races:
  only the current lease owner can publish a key or release the lease, and cache
  outages no longer cause uncoordinated `/pubkey` requests.
- Reject non-canonical or structurally malformed ECDSA DER signatures before any
  automatic public-key request.
- Accept undocumented subscription statuses and the `new`/`success`/`failed`
  charge vocabulary in `WebhookPayload` predicates.

### Added

- Response-header capture, `Retry-After` access, and bounded retries for safe
  idempotent requests.
- Cached and injectable webhook public-key providers with atomic shared-cache
  cooldowns, a configurable refresh lease (120-second minimum and default), Redis
  Cluster-safe default key namespaces, process-local outage protection, key
  validation, stale fallback, and one automatic rotation retry.
- Process-shared in-memory public-key cache fallback
  (`ArrayPublicKeyCache::processShared()`, with a once-per-process deprecation)
  when `MonoClient` has neither `publicKeyCache` nor `publicKeyProvider`, so
  repeated client construction in one worker reuses the key and the refresh
  cooldown instead of failing closed.
- Per-call header redaction customization in `SensitiveData::sanitizeHeaders()` via
  `$additionalSafeHeaders`, while header names matching sensitive aliases remain
  redacted and cannot be allowlisted.
- `PublicKeyProviderInterface` with separate cached-read and forced-refresh
  methods, plus `PublicKeyCacheInterface` with atomic acquire,
  owner-conditional publish, and owner-conditional release operations.
- Typed `SaveCardData` and current `metadata`, `splitReceiverId`, and discount
  `tax` request fields.
- `WalletService` (`getCards()`, `payByToken()`, `deleteCard()`) with the typed
  `WalletPaymentData` request model.
- `InvoiceService::getReceipt()` and `getFiscalChecks()`;
  `MerchantService::getSubmerchants()` and `getSplitReceivers()`.
- `WebhookPayload::errCode()`, `tipsInfo()`, and `SUBSCRIPTION_PAYMENT_STATUSES`.
- Optional `X-Cms` / `X-Cms-Version` headers via the `cms_name` / `cms_version`
  `httpOptions` keys.
- Release-gate test suites (`composer test:release`, also part of `check`):
  OpenAPI contract tests against the stored `v2412` document, 1.x migration
  scenarios, regression reproductions, and documentation consistency checks.
- Documentation: a first-payment walk-through (`docs/getting-started.md`),
  per-capability invoice examples with activation requirements, and an API
  coverage matrix (`docs/api-coverage.md`).
- Runnable reference webhook integration (`examples/Webhooks/`, a copy-and-own
  template that is not autoloaded for consumers): event key,
  entity lock, `modifiedDate` check, state change, and outbox job in one
  transaction, plus an at-least-once `OutboxWorker`; covered by an integration
  test for duplicate, out-of-order, crashed, and concurrent deliveries.
- PHPUnit unit/integration tests, PHPStan level 8, PSR-12 checks, and a PHP 8 CI
  matrix.
- CI checks for the webhook integration on MySQL 8.0, MariaDB 10.5, PostgreSQL 16,
  and for the documented Composer copy procedure on macOS.
- Local validation for identifiers, pagination, RFC3339 ranges, statement ranges,
  and subscription actions.

### Changed

- Core service instances are cached by `MonoClient`.
- `ArrayPublicKeyCache` is an explicit single-process/testing option; custom
  providers take precedence when both provider and cache dependencies are
  supplied.
- `ext-curl` and `ext-openssl` are optional Composer suggestions when their
  corresponding built-in features are not used.
- Live create smoke tests require `MONO_LIVE_CREATE_CONFIRM=yes`; missing live-test
  credentials now produce a successful skip instead of a failing exit code.

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
