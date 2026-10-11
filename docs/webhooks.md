# Webhooks

Monobank sends webhook signatures in the `X-Sign` header. Verify the raw request
body before parsing JSON or applying side effects.

## Verify and Parse

`MonoClient::webhooks()` verifies webhooks out of the box. Without an explicit
shared `publicKeyCache` or `publicKeyProvider` it caches the Monobank public key
in `ArrayPublicKeyCache::processShared()`, one in-memory store for every client in
the process keyed per base URL and merchant token, and emits one `E_USER_DEPRECATED` notice
per process; that fallback is fine for a single worker but cannot coordinate
`/pubkey` refreshes across PHP-FPM or queue workers and is removed in 3.0.
Configure a shared cache for production (see below).

```php
use Vladchornyi\Mono\Exceptions\MonoPublicKeyUnavailableException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;

$rawBody = file_get_contents('php://input');
$xSign = $_SERVER['HTTP_X_SIGN'] ?? null;

try {
    $payload = $mono->webhooks()->parseVerified($rawBody, $xSign);
} catch (MonoPublicKeyUnavailableException $e) {
    error_log('Monobank webhook deferred: ' . $e->getMessage());
    http_response_code(503);   // signature not checked yet: let Monobank retry
    exit;
} catch (MonoWebhookException $e) {
    http_response_code(400);   // forged or malformed: do not retry
    exit;
}
```

`MonoPublicKeyUnavailableException` extends `MonoWebhookException`, so catch it
first. It is thrown when the SDK cannot obtain a validated public key and has no
cached one: the shared cache is down, a refresh is rate limited or still running
in another worker, or `/pubkey` returned no usable key. The request was not
proven forged, so answer with a retryable status and alert on it. Before 2.1 these
cases surfaced as a plain `MonoWebhookException`, and the handler above answered
400 to genuine payments. Transport and API failures while fetching the key still
surface as `MonoTransportException` and `MonoApiException`; treat them as 5xx.

If your application already distributes a trusted Monobank public key, pass it as
the third argument. This path needs neither a cache nor a provider and never
refreshes the key:

```php
$payload = $mono->webhooks()->parseVerified(
    $rawBody,
    $xSign,
    $trustedPublicKeyBase64
);
```

`parse()` and `parsePayload()` also work without public-key configuration, but
they only decode data. Do not apply webhook side effects until the raw body has
been signature-verified.

## Typed Payload Helper

Use `parseVerifiedPayload()` when you want convenient accessors instead of raw
array lookups.

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);

echo $event->type();            // invoice, subscription_status, subscription_charge
echo $event->entityKey();       // stable invoice/subscription key
echo $event->deduplicationKey(); // evt:v2:<hash>, canonical retry key
echo $event->invoiceId();
echo $event->subscriptionId();
echo $event->status();
echo $event->errCode();         // Monobank failure code, see "Помилки в процесі оплати"
echo $event->amount();
echo $event->ccy();
echo $event->modifiedDate()?->format(DATE_ATOM);

$safeForLogs = $event->safeArray();
```

Payload types are inferred from identifiers:

- `invoiceId` only: `invoice`.
- `subscriptionId` only: `subscription_status`.
- `subscriptionId` plus `invoiceId`: `subscription_charge`.

## Key Rotation

Verification without an explicit key handles rotation automatically: on a
signature mismatch the SDK refreshes the key once (rate limited) and retries.
If you pass your own cached key, refresh it with `refreshPublicKey()` on
failure; `getPublicKey()` returns the SDK's cached key and is not a refresh.
The 1.x `getPublicKey()` retry pattern is covered in the
[upgrade guide](../UPGRADING.md#key-rotation-handler-from-1x).

## Cache the Public Key

Passing `publicKeyCache` asks `MonoClient` to build the standard
`CachedPublicKeyProvider`. It uses a one-hour fresh TTL, a validated stale fallback,
and a refresh cooldown. It retries verification once after a rate-limited refresh
to recover from key rotation. Malformed Base64/DER signatures are rejected before
any key request. When constructing `CachedPublicKeyProvider` directly, the cache is
its required second constructor argument.

`ArrayPublicKeyCache` is an explicit in-memory option for tests and single-process
programs only. It cannot coordinate PHP-FPM workers, queue workers, containers, or
hosts. Production multi-worker applications must inject a shared implementation of
`PublicKeyCacheInterface`. Its `add()`, `setIfValue()`, and `deleteIfValue()` methods
must be atomic:

- `add()` acquires the refresh lease only when it does not already exist;
- `setIfValue()` publishes a key only while the expected owner still holds that
  lease;
- `deleteIfValue()` releases a lease only when the expected owner still holds it.

The refresh cooldown must be positive; `minimumRefreshInterval: 0` is rejected so
untrusted signature failures cannot disable cross-request rate limiting.

The refresh lease has a 120-second minimum and default. Increase it with the
`CachedPublicKeyProvider` constructor's `refreshLockTtl` argument when the
worst-case total duration of every `/pubkey` HTTP attempt plus retry delays is
longer. If a lease expires while a request is running, each later lease expiry may
admit another replacement refresh. Stale owners still cannot publish over the
current owner or release its lease. The default HTTP retry budget fits within the
default lease; custom clients must provide an equivalent finite-duration bound.

To build the service without `MonoClient`, use the factory:

```php
$webhooks = WebhookService::create($publicKeyProvider, $verifier);
```

For custom environments, you can tune concurrent-refresh timing only when you
build a custom `CachedPublicKeyProvider` and pass it as `publicKeyProvider` to
`MonoClient`:

```php
$publicKeyProvider = new CachedPublicKeyProvider(
    $mono->pubkey(),
    cache: $publicKeyCache,
    refreshWaitMilliseconds: 75,
    refreshPollMilliseconds: 5
);

$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    publicKeyProvider: $publicKeyProvider
);
```

Do not pass `refreshWaitMilliseconds` or `refreshPollMilliseconds` to
`MonoClient` directly; leave defaults in place unless you explicitly instantiate
`CachedPublicKeyProvider` yourself.

The Redis adapter below performs all ownership checks in Lua. The standard provider
puts its credential fingerprint in a Redis Cluster hash tag (`{...}`), so its record
and lease suffixes stay in the same slot. The adapter's physical prefix deliberately
does not add another hash tag. If you construct `CachedPublicKeyProvider` with a
custom `cacheKey`, it must be unique for the API base URL and merchant credential.
When the backend is Redis Cluster, it must also contain one stable non-empty hash
tag shared by the record and its lease, for example
`mono:{merchant-a}:webhook-public-key`. Redis Cluster rejects a multi-key script
whose `KEYS` span slots.

Use a dedicated phpredis connection with serialization and compression disabled.
`EVAL` does not pack Lua arguments, so mixing packed `SET` values with raw Lua
arguments would make owner comparison and record decoding unreliable. The adapter
validates this precondition and works with either standalone Redis or Redis
Cluster.

```php
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;
use Vladchornyi\Mono\MonoClient;

final class RedisPublicKeyCache implements PublicKeyCacheInterface
{
    private const KEY_PREFIX = 'mono-sdk:';

    public function __construct(private \Redis|\RedisCluster $redis)
    {
        if ($redis->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE) {
            throw new \InvalidArgumentException('Redis serialization must be disabled.');
        }

        if (
            defined('Redis::OPT_COMPRESSION')
            && defined('Redis::COMPRESSION_NONE')
            && $redis->getOption((int) constant('Redis::OPT_COMPRESSION'))
                !== (int) constant('Redis::COMPRESSION_NONE')
        ) {
            throw new \InvalidArgumentException('Redis compression must be disabled.');
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->redis->get($this->key($key));

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        if (!$this->redis->setex($this->key($key), $ttl, $value)) {
            throw new \RuntimeException('Failed to cache the Monobank public key.');
        }
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        return $this->redis->set(
            $this->key($key),
            $value,
            ['nx', 'ex' => $ttl]
        ) === true;
    }

    public function setIfValue(
        string $conditionKey,
        string $expectedValue,
        string $key,
        string $value,
        int $ttl
    ): bool {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end
redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[3])
return 1
LUA;

        return (int) $this->redis->eval(
            $script,
            [
                $this->key($conditionKey),
                $this->key($key),
                $expectedValue,
                $value,
                (string) $ttl,
            ],
            2
        ) === 1;
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        $script = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

        return (int) $this->redis->eval(
            $script,
            [$this->key($key), $expectedValue],
            1
        ) === 1;
    }

    public function delete(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    private function key(string $key): string
    {
        return self::KEY_PREFIX . $key;
    }
}

$mono = new MonoClient(
    apiKey: $_ENV['MONO_KEY'],
    publicKeyCache: new RedisPublicKeyCache($redis)
);
```

The standard provider derives its default logical key from the API base URL and a
one-way merchant-token fingerprint. If the shared cache is unavailable, it uses a
validated local or stale record when allowed and otherwise fails closed with
`MonoPublicKeyUnavailableException`; it never issues an uncoordinated `/pubkey`
request and does not make later calls wait for a refresh that cannot happen.

When implementing `PublicKeyCacheInterface`, throw `Exception` for operational
backend failures (for example connectivity, timeouts, or I/O errors). The SDK's
cache wrapper catches only `Exception`, so `TypeError` and other `Error`
instances are treated as programming problems and are not masked by fallback
logic.

For a completely custom provider, implement both
`PublicKeyProviderInterface::getPublicKey()` and `refreshPublicKey()` and inject it
through `publicKeyProvider`. If both `publicKeyProvider` and `publicKeyCache` are
supplied, the custom provider takes precedence and the cache is not wrapped by the
SDK for webhook verification.

## Reject Stale Events

Webhook events are not guaranteed to arrive in chronological order. Store
`modifiedDate` locally and apply only newer events. Monobank timestamps carry
nanoseconds and PHP's `DateTime` keeps only microseconds, so store the raw
string (or `$event->modifiedInstant()->toRfc3339()`) rather than a `DateTime`
and pass that string back; `shouldApply()` then orders events exactly. A
`DateTimeInterface` is still accepted for compatibility.

```php
if (!$mono->webhooks()->shouldApply($payload, $storedModifiedDate)) {
    return ['message' => 'stale'];
}
```

`modifiedDate` is optional in the Monobank schema (card-tokenization status
callbacks, for example), so a payload without it is applied by default and must
be deduplicated with `deduplicationKey()`. Pass `allowMissingModifiedDate: false`
to reject such payloads; the rejection applies whether or not a timestamp has
been stored for the entity yet.

Monobank never sends a webhook for the `expired` status. Poll
`getInvoiceStatus()` for invoices that have passed their `validity` without a
terminal callback.

With the typed helper:

```php
if (!$mono->webhooks()->shouldApplyPayload($event, $storedModifiedDate)) {
    return ['message' => 'stale'];
}
```

## Reference Handler: One Transaction, Then an Outbox

Persisting `deduplicationKey()` first and changing the invoice afterwards is not
safe: if the process dies in between, the redelivery looks like a duplicate and
the payment is never applied. Two deliveries of the same event can also race.
The runnable example in [`examples/Webhooks/`](../examples/Webhooks/) does the
following inside **one** database transaction and acknowledges only after commit:

1. Verify `X-Sign` and parse the payload (outside the transaction).
2. Insert `deduplicationKey()` into `webhook_events` (primary key). A unique
   violation means the event was already committed: respond 200 and stop.
3. Lock the entity row in `webhook_entities` by `entityKey()` (`SELECT ... FOR
   UPDATE`; `BEGIN IMMEDIATE` on SQLite) so a concurrent delivery for the same
   invoice waits or is told to retry with HTTP 503.
4. Compare `modifiedDate` with the stored value while holding the lock. A stale
   event keeps its key (its retries are cheap duplicates) but changes nothing.
5. Update only the fields the event carries (`status`, `modified_at`,
   `wallet_status`/`wallet_id` from `walletData`) and insert a job into
   `webhook_outbox`, keyed by the same deduplication key, in the same
   transaction. A tokenization update without `status`/`modifiedDate` never
   clears the payment status or the stale-protection date. No e-mail, ERP
   call, or fulfilment happens here.
6. Commit, then respond 200.

`OutboxWorker` executes the jobs after commit with at-least-once semantics
(lease, run, mark done) and hands every dispatcher the `idempotency_key`, so a
re-run after a crash cannot repeat an external effect. Jobs of one entity run
strictly in insertion order across all workers: a `reversed` is never leased
while the preceding `success` is unfinished, even on another worker. Leases are
timed per job and carry an owner token; only the owner can mark a job done, so a
worker that outlived its lease cannot complete a job another worker took over.
An exhausted job blocks later jobs of its own entity, while other entities
continue: the worker filters out blocked successors before applying its batch
limit. Resolve exhausted jobs manually before their entity can resume.

The example classes are shipped in the package but **not autoloaded** for
consumers (their namespace is `autoload-dev` only). Copy them into your
application and rename the namespace, as described in
[`examples/Webhooks/README.md`](../examples/Webhooks/README.md):

```bash
mkdir -p src/Payments/Monobank
cp vendor/vladchornyi/mono/examples/Webhooks/{TransactionalWebhookProcessor.php,OutboxWorker.php,schema*.sql} src/Payments/Monobank/
sed -i.bak 's/namespace Vladchornyi\\Mono\\Examples\\Webhooks;/namespace App\\Payments\\Monobank;/' src/Payments/Monobank/*.php
```

`sed -i.bak` works on macOS and Linux and preserves the original files as `.bak`
copies. Apply `schema.sql` for SQLite, `schema.mysql.sql` for MySQL/MariaDB, or
`schema.pgsql.sql` for PostgreSQL using your migration tool. Existing tables
need explicit migrations for missing columns/indexes.

```php
use App\Payments\Monobank\TransactionalWebhookProcessor;
use Vladchornyi\Mono\Exceptions\MonoPublicKeyUnavailableException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;

$processor = new TransactionalWebhookProcessor($pdo, $mono->webhooks());

try {
    $outcome = $processor->handle($rawBody, $xSign);  // applied | duplicate | stale | retry
} catch (MonoPublicKeyUnavailableException $e) {
    $outcome = TransactionalWebhookProcessor::OUTCOME_RETRY;
} catch (MonoWebhookException $e) {
    http_response_code(400);
    exit;
}

http_response_code($outcome === TransactionalWebhookProcessor::OUTCOME_RETRY ? 503 : 200);
```

Completed outbox jobs keep only a redacted payload, so card tokens and wallet ids
do not stay in `webhook_outbox` after the dispatcher has handled them. The
stand-alone demo reads its database from `MONO_WEBHOOK_DSN` (never a file next to
`index.php`) and creates the tables once with `php examples/Webhooks/migrate.php`.

`tests/Integration/TransactionalWebhookProcessorTest.php` in the library
repository (tests are not part of the Composer package) proves the guarantees
on SQLite: out-of-order and repeated deliveries change state exactly once, a
crash between the key write and the state change leaves nothing behind so the
redelivery is applied, two concurrent deliveries serialize on the entity lock,
and the outbox retries a failed job with the same idempotency key.

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

Monobank does not publish the `chargeUrl` / `statusUrl` payload schemas. The
shapes below reflect observed callbacks; the documented subscription statuses are
only `active` and `cancelled`, and charge statuses follow the payment history
vocabulary `new`, `success`, `failed`. Treat other values as possible and keep
handlers tolerant.

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
  "modifiedDate": "2026-07-01T10:02:00Z",
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
