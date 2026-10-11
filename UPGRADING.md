# Upgrading from 2.0 to 2.1

2.1 is a security and robustness release. Every 2.0 call keeps its signature
and behavior for valid production setups, with one deliberate exception: a
plain-HTTP API base URL.

## What Can Stop Requests

1. **`baseUrl` must use HTTPS.** `MonoClient` and directly constructed services
   now throw `InvalidArgumentException` for an `http://` base URL, because every
   request carries the merchant token in `X-Token`. Loopback hosts (`localhost`,
   `127.0.0.0/8`, `[::1]`) stay allowed for local test servers and same-host TLS
   proxies. The default `https://api.monobank.ua/api/merchant` is unaffected.

## Recommended Changes

1. **Answer 503 when the public key is unavailable.** The new
   `MonoPublicKeyUnavailableException` extends `MonoWebhookException`, so 2.0
   handlers still catch it, but they answer 400 to genuine payments whose
   signature could not be checked. Catch it first:

   ```php
   use Vladchornyi\Mono\Exceptions\MonoPublicKeyUnavailableException;
   use Vladchornyi\Mono\Exceptions\MonoWebhookException;

   try {
       $event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);
   } catch (MonoPublicKeyUnavailableException $e) {
       http_response_code(503);   // shared cache down, refresh rate limited, or no usable key
       exit;
   } catch (MonoWebhookException $e) {
       http_response_code(400);   // forged or malformed
       exit;
   }
   ```

   When the shared cache is down, the message now says so (`The shared
   public-key cache is unavailable and no cached key is available.`) instead of
   reporting a rate limit, and later calls no longer sleep while polling for a
   refresh that cannot start.
2. **Custom `HttpClientInterface` implementations** should repeat
   `#[\SensitiveParameter]` on `$url`, `$headers`, and `$body`; PHP does not
   inherit parameter attributes. On PHP 8.0 and 8.1, which ignore the attribute,
   set `zend.exception_ignore_args=On` so traces never carry the token. See
   [Errors and Safe Logging](docs/errors-and-logging.md#exception-traces).
3. **Paginated reads**: replace hand-written page loops with
   `iterateSubscriptionList()` and `iterateSubscriptionPayments()`. A response
   without `list` is an empty page, not an error.
4. **Copied webhook examples**: copy `OutboxWorker.php` again to get payload
   redaction for completed jobs. The stand-alone demo now needs
   `php examples/Webhooks/migrate.php` once and reads `MONO_WEBHOOK_DSN`.

## Unchanged

- Public method names, parameters, and return types of 2.0. Parameter
  attributes do not change signatures.
- `catch (MonoWebhookException $e)` still matches every webhook failure.
- `CurlHttpClient::__destruct()` still exists for subclasses; it no longer calls
  `curl_close()`, which PHP 8.5 deprecates.
- The Composer package no longer ships `tests/` or the tool configuration files;
  nothing under `src/`, `docs/`, or `examples/` was removed.

# Upgrading from 1.x to 2.0

Every statement below was checked against the `v1.4.0` tag and the 2.0 code.
Changes are grouped into **breaking** (1.x code behaves differently or fails),
**added** (new, opt-in), and **unchanged** (works exactly as in 1.x).

## Migration Route

1. `composer require vladchornyi/mono:^2.0`.
2. Run your test suite. A 1.x integration that only creates invoices, polls
   status, and verifies webhooks through `MonoClient::webhooks()` needs **no code
   changes for valid requests**; discount values must be multiples of `0.01`
   (see below). Expect one `E_USER_DEPRECATED` notice per process about the
   public-key cache.
3. Check [What Can Stop Payments](#what-can-stop-payments).
4. Before 3.0: inject a shared `publicKeyCache` on multi-worker deployments,
   replace `eventKey()` with `deduplicationKey()` + `entityKey()`, and build
   `WebhookService` through `create()` or `MonoClient::webhooks()`.

## What Can Stop Payments

1. **`cancelSubscription()` changed endpoint.** It now calls `/subscription/edit`
   with `action=cancel`. Code that relied on it hitting `/subscription/remove`
   (which only works before the first charge) must call `removeSubscription()`.
2. **`SubscriptionData` became more permissive, not less**: any ISO 4217 code and
   any `{n}{d|w|m|y}` interval are accepted, `redirectUrl` and `webHookUrls` may
   be `null`. Local validation that used to fail early now reaches Monobank; keep
   handling `MonoApiException` (400).
3. **Exception class for malformed webhook JSON** changed from `MonoJsonException`
   to `MonoWebhookException` in `WebhookService::parse()`. A
   `catch (MonoJsonException $e)` around `parse()`/`parseVerified()` no longer
   matches; `catch (MonoException $e)` or `catch (MonoWebhookException $e)` (the
   form recommended in the 1.x docs) does.
4. **The 1.x key-rotation handler no longer rotates.** `getPublicKey()` now
   returns the cached key instead of calling `/pubkey`, so the documented
   `catch` block that retried with `getPublicKey()` retries with the same stale
   key after Monobank rotates. Replace it as shown in
   [Key Rotation Handler from 1.x](#key-rotation-handler-from-1x).
5. **Discount values now enforce the documented `0.01` step.** Values such as
   `1.001` throw `InvalidArgumentException`, including after changing the public
   `value` property. Calculate discounts at two-decimal precision before building
   the request. Ordinary binary float noise (`0.1 + 0.2`) is normalized to `0.3`;
   meaningful fractional cents are rejected rather than silently rounded.

## Webhook Verification Behavior

| Setup | 1.x | 2.0 |
| --- | --- | --- |
| `MonoClient` without `publicKeyCache` / `publicKeyProvider`, `->webhooks()->verify($body, $xSign)` | Fetched `/pubkey` on every verification | Works. Key cached in `ArrayPublicKeyCache::processShared()` (one store per process, isolated per base URL and token). One `E_USER_DEPRECATED` per process. On signature mismatch: one rate-limited refresh, then `false`. **Removed in 3.0.** |
| `MonoClient` with shared `publicKeyCache` (Redis etc.) | not available | Works. Cross-worker refresh lease and cooldown; no deprecation. Recommended for PHP-FPM, queues, containers. |
| `MonoClient` with custom `publicKeyProvider` | not available | Works. Provider takes precedence over `publicKeyCache`; no deprecation. |
| Direct `new WebhookService()` / `WebhookService::create()` without provider, automatic key | not possible (constructor required `PubkeyService`) | Throws `MonoWebhookException` before any HTTP request (fails closed). |
| Direct `new WebhookService($pubkeyService, $verifier)` (any 1.x call form) | Fetched `/pubkey` on every verification | Works with a deprecation; wrapped in the process-shared cache like the first row. |
| Explicit key: `verify($body, $xSign, $publicKeyBase64)` | Works | Works. Never fetches or refreshes; independent of cache/provider configuration. |
| `parse()` / `parsePayload()` without verification | Works | Works. Decodes only; no key needed. |

## Breaking Changes

| Area | 1.4.0 | 2.0 | Migration |
| --- | --- | --- | --- |
| `SubscriptionService::cancelSubscription()` | `POST /subscription/remove` | `POST /subscription/edit`, `action=cancel`, optional `refundAmount` | Use `removeSubscription()` for uncharged subscriptions. |
| `WebhookService::parse()` on invalid JSON | `MonoJsonException` | `MonoWebhookException` | Catch `MonoWebhookException` or `MonoException`. |
| `WebhookVerifier::verify()` | Accepted any base64 signature | Rejects non-canonical or malformed ECDSA DER signatures and non-EC keys before `/pubkey` | None for genuine Monobank traffic. |
| `WebhookService::parse()` on `[]`, `{}`, JSON lists | Returned the decoded array | `MonoWebhookException` | None for genuine Monobank traffic. |
| `WebhookPayload::*Date()` parsing | Lenient `new DateTimeImmutable($value)` | Strict RFC3339, 0 to 9 fractional digits | Only non-RFC3339 strings are affected. |
| `shouldApply()` stored-date parameter | `?DateTimeInterface` | `DateTimeInterface|Instant|string|null` | Optional: store the raw `modifiedDate` string for nanosecond ordering. |
| `SubscriptionData` constructor | `string $redirectUrl`, `array $webHookUrls` required; `ccy` only 980/840; interval only `1m|3m|6m|1y` | Both nullable; any ISO 4217 code; `{n}{d|w|m|y}` | None (superset). |
| `InvoiceData::$saveCardData` array | Any keys passed through; `saveCard` optional | Unknown keys dropped; `saveCard` required; `walletId` must be a non-empty string when present | Add `saveCard`. Prefer `SaveCardData`. |
| `InvoiceData::$paymentType` | `debit|hold` | `debit|hold|verification` (amount 0 + `saveCard` required) | None. |
| `MonoClient` service accessors | New instance per call | Cached per client | Only matters if you mutated a service instance. |
| `AbstractService` constructor | 4 parameters | 5th `array $defaultHeaders = []` | Update subclasses that override the constructor. |
| `DiscountItem`, `BasketOrderItem`, `MerchantPaymInfoItem` lists | Keys preserved (`unset()` could produce JSON objects) | Re-indexed to JSON lists; empty `metadata` omitted | None. |
| `DiscountItem::$value` | Any finite number within the range | Must also be a multiple of `0.01`; binary float noise normalized | Calculate values at two-decimal precision; handle `InvalidArgumentException`. |
| `MerchantPaymInfoItem::$customerEmails` | Duplicates sent unchanged | Exact duplicates removed during serialization, first occurrence retained | None; addresses are not lowercased or otherwise rewritten. |
| `Content-Type` request header | Sent as a malformed list entry (bug) | Sent as `Content-Type: application/json` | None. |

Deprecated but still working (removed in 3.0):

- `WebhookPayload::eventKey()` returns `entityKey()` (same `type:id` values).
- `new WebhookService($pubkeyService, $verifier)` in positional, named, or mixed form.
- `MonoClient::webhooks()` without `publicKeyCache` / `publicKeyProvider`.

## Added

- `WebhookPayload::deduplicationKey()` (`evt:v2:<sha256>` of the canonical
  payload), `entityKey()`, `modifiedInstant()`, `errCode()`, `tipsInfo()`,
  `SUBSCRIPTION_PAYMENT_STATUSES`.
- `Support\Instant`, `Rfc3339::parseInstant()`, `WebhookService::getModifiedInstant()`.
- `WebhookService::create()`, `shouldApply(..., allowMissingModifiedDate)`.
- `PublicKeyProviderInterface`, `PublicKeyCacheInterface`, `CachedPublicKeyProvider`,
  `ArrayPublicKeyCache`, `MonoClient` parameters `publicKeyProvider` / `publicKeyCache`.
- `SaveCardData`, `WalletPaymentData`, `MonoClient::wallet()` (`getCards()`,
  `payByToken()`, `deleteCard()`), `InvoiceService::getReceipt()`,
  `getFiscalChecks()`, `MerchantService::getSubmerchants()`, `getSplitReceivers()`,
  `SubscriptionService::removeSubscription()`.
- Model fields `successUrl`, `failUrl`, `displayType`, `withAppUrl`,
  `BasketOrderItem::$splitReceiverId`, `MerchantPaymInfoItem::$metadata` (max 10),
  `DiscountItem::$tax`; `validity` above 30 days is clamped instead of sent.
- `httpOptions` keys `cms_name` / `cms_version` (`X-Cms` / `X-Cms-Version` headers).
- HTTP: response-header capture, `MonoApiException::getResponseHeaders()`,
  `getResponseHeaderLine()`, `getSafeContext()`, `getSafeUrl()`, `getPath()`,
  `getErrorCode()`; bounded retries for `GET`/`HEAD`/`OPTIONS` on 429/502/503/504
  honoring `Retry-After`; validation of methods, headers, and URLs; redirects
  never followed.
- `SensitiveData::sanitizeHeaders()`, `sanitizeUrl()`, `urlPath()`; more redacted
  key aliases.
- Reference transactional webhook handler in `examples/Webhooks/`.

## Unchanged

- `MonoClient` constructor positional parameters `apiKey`, `baseUrl`,
  `httpClient`, `httpOptions` (new ones are appended).
- `InvoiceService::createInvoice()`, `getInvoiceStatus()`, `cancelInvoice()`,
  `removeInvoice()`, `invalidateInvoice()`, `finalizeHold(string, int, ?array)`
  (`amount` was required in 1.x and still is).
- `SubscriptionService::createSubscription()`, `getSubscriptionStatus()`,
  `getSubscriptionPayments()`, `getSubscriptionList()`, `editSubscription()`.
- `StatementService::getStatement()`, `MerchantService::getDetails()`,
  `PubkeyService::get()`.
- `WebhookService::verify()`, `parseVerified()`, `parsePayload()`,
  `parseVerifiedPayload()`, `shouldApply()` defaults (a payload without
  `modifiedDate` is applied, as in 1.x), `getModifiedDate()`.
- All `WebhookPayload` accessors present in 1.x.
- `MonoApiException::getStatusCode()`, `getMethod()`, `getUrl()` (still the raw
  URL), `getResponseBody()`, `getResponseData()`; `getMessage()` now omits the
  query string.
- `InvoiceData::$validity <= 0` and `amount <= 0` still throw; 1.4.0 had no
  30-day check, so no existing call is rejected by the new clamp.
- `HttpClientInterface::send()` signature and `HttpResponse`.

## Webhook Deduplication Migration

`deduplicationKey()` has the format `evt:v2:<sha256>` and hashes the canonical
event payload together with its entity key. JSON object key order does not change
the result; an actual state or timestamp change does.

The 1.x `eventKey()` value (`type:id`) identifies an entity, not an event, so
using it for replay protection can discard a later `success` after an earlier
`processing`. Recommended sequence:

1. Add a storage namespace for `evt:v2:` keys.
2. Deploy code that checks `modifiedDate` (store the raw string) and the new
   `deduplicationKey()`; see the transactional example in `examples/Webhooks/`.
3. Keep old keys only for audit; do not consult them for new deliveries.
4. Accept that the first delivery after deployment can be processed once more.
5. Remove the old namespace after the retry window and your retention period.

## Key Rotation Handler from 1.x

The 1.x documentation recommended caching the public key yourself and
refreshing it on a verification failure:

```php
// 1.x pattern: stops working after a key rotation in 2.0
try {
    $payload = $webhooks->parseVerified($rawBody, $xSign, $cachedPublicKey);
} catch (\Vladchornyi\Mono\Exceptions\MonoWebhookException $e) {
    $freshPublicKey = $webhooks->getPublicKey();
    $payload = $webhooks->parseVerified($rawBody, $xSign, $freshPublicKey);
}
```

In 1.x `getPublicKey()` performed an HTTP request every time. In 2.0 it returns
the key from the SDK cache, which still holds the old key at that moment, so the
retry fails with the same `MonoWebhookException`. Two replacements:

**A. Drop the explicit key (recommended).** The SDK caches the key and, on a
signature mismatch, performs one rate-limited refresh and retry itself:

```php
$payload = $webhooks->parseVerified($rawBody, $xSign);
```

**B. Keep your own cache, refresh explicitly.** Replace `getPublicKey()` with
`refreshPublicKey()`, which forces one `/pubkey` request (subject to the
refresh cooldown) and returns the new key; store it in your cache:

```php
try {
    $payload = $webhooks->parseVerified($rawBody, $xSign, $cachedPublicKey);
} catch (\Vladchornyi\Mono\Exceptions\MonoWebhookException $e) {
    $freshPublicKey = $webhooks->refreshPublicKey();
    $yourCache->set('monobank-public-key', $freshPublicKey);
    $payload = $webhooks->parseVerified($rawBody, $xSign, $freshPublicKey);
}
```

`refreshPublicKey()` is rate limited (`minimumRefreshInterval`, 30 s by
default): during the cooldown it returns the currently cached key, so a burst of
webhooks right after a rotation is served by the first refresh. Both
replacements are exercised by `tests/Migration/V1CompatibilityTest.php`.

## `WebhookService` Construction

The constructor keeps the 1.x parameter names and order (`pubkeyService`,
`verifier`) and adds a third `publicKeyProvider`:

| 1.x call | 2.0 | Replacement |
| --- | --- | --- |
| `new WebhookService($pubkey, $verifier)` | works, deprecated | `WebhookService::create($provider, $verifier)` |
| `new WebhookService(pubkeyService: $pubkey, verifier: $verifier)` | works, deprecated | `WebhookService::create(publicKeyProvider: $provider, verifier: $verifier)` |
| `new WebhookService($pubkey, verifier: $verifier)` | works, deprecated | `WebhookService::create($provider, $verifier)` |
| `new WebhookService($mono->pubkey())` | works, deprecated | `$mono->webhooks()` |

Passing both `pubkeyService` and `publicKeyProvider` throws
`InvalidArgumentException`. In 3.0 the constructor becomes
`(?WebhookVerifier $verifier, ?PublicKeyProviderInterface $publicKeyProvider)`;
code using `create()` or `MonoClient::webhooks()` will not change.

## Shared Public-Key Cache (Production Detail)

Inject a shared cache so every worker reuses one key and one refresh lease:

```php
$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    publicKeyCache: $sharedPublicKeyCache
);
```

A `PublicKeyCacheInterface` adapter must implement three atomic operations:

- `add($key, $value, $ttl)` acquires a lease only when the key is absent;
- `setIfValue($conditionKey, $expectedValue, $key, $value, $ttl)` writes the
  key record only while the lease still holds the expected owner token;
- `deleteIfValue($key, $expectedValue)` releases the lease only for its owner.

Do not implement the last two as `get()` followed by `set()`/`delete()`; the
comparison and mutation must be one datastore operation. The
[Redis/Lua reference adapter](docs/webhooks.md#cache-the-public-key) shows the
required scripts. The built-in provider validates Base64, PEM, and EC key type
before caching, performs at most one rate-limited refresh on a signature
mismatch, can serve a validated stale key when a refresh fails, and never issues
uncoordinated `/pubkey` requests during cache outages.

Tunables on `CachedPublicKeyProvider`: `refreshLockTtl` (120 s minimum and
default; keep it above the worst-case `/pubkey` HTTP attempt and retry budget),
`minimumRefreshInterval` (must be positive), `ttl`, `staleTtl`. The default cache
key embeds a fingerprint of the base URL and token inside a Redis Cluster hash
tag; a custom `cacheKey` must keep that isolation and, on Redis Cluster, a stable
hash tag such as `mono:{merchant-a}:webhook-public-key`.

Custom providers implement `PublicKeyProviderInterface::getPublicKey()` (cached
read) and `refreshPublicKey()` (one rate-limited refresh); when both
`publicKeyProvider` and `publicKeyCache` are given, the provider wins.

Verification steps before relying on the shared cache:

1. Owner-mismatch tests for `setIfValue()` / `deleteIfValue()` pass.
2. The refresh lease exceeds the HTTP retry budget; on Redis Cluster related
   keys share a slot.
3. Every webhook worker receives the same `publicKeyCache` / `publicKeyProvider`.
4. The deprecation notice from the process-local fallback no longer appears in
   logs.

## Live Tests

Live create tests require `MONO_LIVE_CREATE_CONFIRM=yes`. If cleanup is disabled
or fails, the JSON output includes the complete resource ID needed for manual
cleanup.
