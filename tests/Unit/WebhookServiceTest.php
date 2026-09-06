<?php

namespace Vladchornyi\Mono\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;
use Vladchornyi\Mono\Contracts\PublicKeyProviderInterface;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Services\PubkeyService;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Support\Instant;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Tests\Support\FaultInjectingPublicKeyCache;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;
use Vladchornyi\Mono\Webhooks\CachedPublicKeyProvider;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

final class WebhookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetProcessGuards();

        if (!extension_loaded('openssl')) {
            self::markTestSkipped('OpenSSL is not installed.');
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->resetProcessGuards();
        }
    }

    private function resetProcessGuards(): void
    {
        $reflection = $this->privateProperty(CachedPublicKeyProvider::class, 'processGuards');
        $reflection->setValue(null, []);

        $warned = $this->privateProperty(MonoClient::class, 'processLocalCacheWarned');
        $warned->setValue(null, false);

        $shared = $this->privateProperty(ArrayPublicKeyCache::class, 'processShared');
        $shared->setValue(null, null);
    }

    private function assertProcessGuardMissing(string $guardKey): void
    {
        $reflection = $this->privateProperty(CachedPublicKeyProvider::class, 'processGuards');

        self::assertArrayNotHasKey($guardKey, $reflection->getValue());
    }

    public function testVerifiedParsingCachesThePublicKey(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $webhooks = (new MonoClient(
            'token',
            'https://example.test/api/merchant',
            $http,
            publicKeyCache: new ArrayPublicKeyCache()
        ))->webhooks();

        self::assertSame('success', $webhooks->parseVerified($payload, $signature)['status']);
        self::assertSame('success', $webhooks->parseVerified($payload, $signature)['status']);
        self::assertCount(1, $http->requests);
    }

    public function testRotatedPublicKeyIsFetchedOnceAndRetried(): void
    {
        [, $oldPublicKey] = $this->keyPair();
        [$newPrivateKey, $newPublicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $newPrivateKey);
        $http = new FakeHttpClient(
            $this->publicKeyResponse($oldPublicKey),
            $this->publicKeyResponse($newPublicKey)
        );
        $webhooks = (new MonoClient(
            'token',
            'https://example.test/api/merchant',
            $http,
            publicKeyCache: new ArrayPublicKeyCache()
        ))->webhooks();

        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertCount(2, $http->requests);
    }

    public function testMalformedSignatureIsRejectedBeforePublicKeyFetch(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient(
            'token',
            'https://example.test/api/merchant',
            $http,
            publicKeyCache: new ArrayPublicKeyCache()
        ))->webhooks();

        $malformedSignatures = [
            'wrong tag' => 'not-a-DER-signature',
            'missing length' => "\x30",
            'empty sequence' => "\x30\x00",
            'indefinite length' => "\x30\x80\x02\x01\x01\x02\x01\x01\x00\x00",
            'non-canonical long length' => "\x30\x81\x06\x02\x01\x01\x02\x01\x01",
            'missing second integer' => "\x30\x03\x02\x01\x01",
            'negative integer' => "\x30\x06\x02\x01\x80\x02\x01\x01",
            'zero integer' => "\x30\x06\x02\x01\x00\x02\x01\x01",
            'unnecessary leading zero' => "\x30\x07\x02\x02\x00\x01\x02\x01\x01",
            'trailing bytes' => "\x30\x06\x02\x01\x01\x02\x01\x01\x00",
        ];

        foreach ($malformedSignatures as $case => $signature) {
            try {
                $webhooks->verify($this->payload(), base64_encode($signature));
                self::fail('Malformed signature was accepted: ' . $case);
            } catch (MonoWebhookException $exception) {
                self::assertStringContainsString('DER signature', $exception->getMessage(), $case);
            }
        }

        self::assertCount(0, $http->requests);
    }

    public function testInvalidTrafficRefreshesAtMostOnceDuringCooldown(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $invalidSignature = $this->sign('{"different":true}', $privateKey);
        $http = new FakeHttpClient(
            $this->publicKeyResponse($publicKey),
            $this->publicKeyResponse($publicKey)
        );
        $webhooks = (new MonoClient(
            'token',
            'https://example.test/api/merchant',
            $http,
            publicKeyCache: new ArrayPublicKeyCache()
        ))->webhooks();

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            self::assertFalse($webhooks->verify($this->payload(), $invalidSignature));
        }

        self::assertCount(2, $http->requests);
    }

    public function testExplicitPublicKeyNeverTriggersNetworkRefresh(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $signature = $this->sign('{"different":true}', $privateKey);
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        self::assertFalse($webhooks->verify($this->payload(), $signature, $publicKey));
        self::assertCount(0, $http->requests);
    }

    public function testProviderReturnsStaleValidatedKeyWhenRefreshFails(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('test-public-key', json_encode([
            'version' => 1,
            'key' => $publicKey,
            'fetchedAt' => time() - 2,
        ], JSON_THROW_ON_ERROR), 3600);
        $http = new FakeHttpClient(new HttpResponse(503, '{"errCode":"TEMPORARY"}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            ttl: 1,
            minimumRefreshInterval: 1,
            staleTtl: 3600,
            cache: $cache,
            cacheKey: 'test-public-key'
        );

        self::assertSame($publicKey, $provider->getPublicKey());
        self::assertCount(1, $http->requests);
    }

    public function testProviderRejectsNonEcKeysBeforeCaching(): void
    {
        $rsaKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsaKey);
        $details = openssl_pkey_get_details($rsaKey);
        self::assertIsArray($details);
        $http = new FakeHttpClient($this->publicKeyResponse(base64_encode($details['key'])));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider($client->pubkey(), cache: new ArrayPublicKeyCache());

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('EC public key');
        $provider->getPublicKey();
    }

    public function testCachedProviderRequiresAnExplicitCoordinationCache(): void
    {
        $client = new MonoClient(
            'token',
            'https://example.test/api/merchant',
            new FakeHttpClient()
        );

        $this->expectException(\ArgumentCountError::class);
        new CachedPublicKeyProvider($client->pubkey());
    }

    public function testCachedProviderRejectsANonPositiveRefreshLockTtl(): void
    {
        $client = new MonoClient(
            'token',
            'https://example.test/api/merchant',
            new FakeHttpClient()
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refresh lock TTL');
        new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: new ArrayPublicKeyCache(),
            refreshLockTtl: 0
        );
    }

    public function testCachedProviderHasConfiguredRefreshWaitAndPollDefaults(): void
    {
        $client = new MonoClient(
            'token',
            'https://example.test/api/merchant',
            new FakeHttpClient()
        );
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: new ArrayPublicKeyCache()
        );
        $wait = $this->privateProperty(CachedPublicKeyProvider::class, 'refreshWaitMilliseconds');
        $poll = $this->privateProperty(CachedPublicKeyProvider::class, 'refreshPollMilliseconds');

        self::assertSame(250, $wait->getValue($provider));
        self::assertSame(10, $poll->getValue($provider));
    }

    /**
     * @dataProvider invalidRefreshTimingProvider
     */
    public function testCachedProviderRejectsInvalidRefreshTiming(
        int $refreshWaitMilliseconds,
        int $refreshPollMilliseconds,
        string $expectedMessage
    ): void {
        $client = new MonoClient(
            'token',
            'https://example.test/api/merchant',
            new FakeHttpClient()
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: new ArrayPublicKeyCache(),
            refreshWaitMilliseconds: $refreshWaitMilliseconds,
            refreshPollMilliseconds: $refreshPollMilliseconds
        );
    }

    /** @return array<string, array{int, int, string}> */
    public function invalidRefreshTimingProvider(): array
    {
        return [
            'non-positive wait' => [0, 10, 'must be positive milliseconds'],
            'non-positive poll' => [10, 0, 'must be positive milliseconds'],
            'poll greater than wait' => [10, 11, 'must not exceed refresh wait interval'],
        ];
    }

    public function testCachedProviderRespectsConfiguredConcurrentRefreshWaitAndPoll(): void
    {
        $client = new MonoClient(
            'token',
            'https://example.test/api/merchant',
            new FakeHttpClient()
        );
        $cache = new class (1) implements PublicKeyCacheInterface {
            private int $getCalls = 0;
            private int $getLockCalls = 0;
            private int $activeLockProbes = 0;
            private int $probesBeforeRelease;

            public function __construct(int $probesBeforeRelease)
            {
                $this->probesBeforeRelease = $probesBeforeRelease;
            }

            public function getProbeCount(string $key): int
            {
                return $key === 'timed-wait:refresh-lock'
                    ? $this->activeLockProbes
                    : $this->getCalls;
            }

            public function get(string $key): ?string
            {
                if ($key === 'timed-wait:refresh-lock') {
                    ++$this->getLockCalls;

                    if ($this->getLockCalls <= $this->probesBeforeRelease) {
                        ++$this->activeLockProbes;

                        return 'in-progress';
                    }

                    return null;
                }

                ++$this->getCalls;

                return null;
            }

            public function set(string $key, string $value, int $ttl): void
            {
                return;
            }

            public function add(string $key, string $value, int $ttl): bool
            {
                if ($key === 'timed-wait:refresh-lock') {
                    return false;
                }

                return true;
            }

            public function setIfValue(
                string $conditionKey,
                string $expectedValue,
                string $key,
                string $value,
                int $ttl
            ): bool {
                return false;
            }

            public function deleteIfValue(string $key, string $expectedValue): bool
            {
                return false;
            }

            public function delete(string $key): void
            {
                return;
            }
        };

        $waiter = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'timed-wait',
            refreshWaitMilliseconds: 50,
            refreshPollMilliseconds: 50
        );

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('refresh is still in progress and no refreshed key is available.');

        try {
            $waiter->getPublicKey();
        } catch (MonoWebhookException $exception) {
            self::assertSame(1, $cache->getProbeCount('timed-wait:refresh-lock'));
            throw $exception;
        }
    }

    /**
     * @dataProvider invalidProviderConfigurationProvider
     */
    public function testProviderRejectsInvalidConfiguration(
        int $ttl,
        int $minimumRefreshInterval,
        int $staleTtl,
        string $cacheKey
    ): void {
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $this->expectException(\InvalidArgumentException::class);
        new CachedPublicKeyProvider(
            $client->pubkey(),
            new ArrayPublicKeyCache(),
            ttl: $ttl,
            minimumRefreshInterval: $minimumRefreshInterval,
            staleTtl: $staleTtl,
            cacheKey: $cacheKey
        );
    }

    /** @return array<string, array{int, int, int, string}> */
    public function invalidProviderConfigurationProvider(): array
    {
        return [
            'zero ttl' => [0, 30, 3600, 'key'],
            'zero refresh interval' => [3600, 0, 3600, 'key'],
            'negative refresh interval' => [3600, -1, 3600, 'key'],
            'stale ttl below ttl' => [3600, 30, 3599, 'key'],
            'empty cache key' => [3600, 30, 3600, ' '],
        ];
    }

    public function testProviderRejectsAResponseWithoutAKey(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('does not contain a key');
        (new CachedPublicKeyProvider($client->pubkey(), cache: new ArrayPublicKeyCache()))->getPublicKey();
    }

    /**
     * @dataProvider invalidCacheRecordProvider
     */
    public function testProviderDiscardsInvalidCacheRecords(string $record): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('invalid-record', $record, 3600);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'invalid-record'
        );

        self::assertSame($publicKey, $provider->getPublicKey());
        self::assertCount(1, $http->requests);
    }

    /** @return array<string, array{string}> */
    public function invalidCacheRecordProvider(): array
    {
        return [
            'invalid json' => ['{'],
            'invalid shape' => [json_encode(['version' => 1], JSON_THROW_ON_ERROR)],
            'invalid public key' => [json_encode([
                'version' => 1,
                'key' => base64_encode('not a PEM key'),
                'fetchedAt' => time(),
            ], JSON_THROW_ON_ERROR)],
            'future timestamp' => [json_encode([
                'version' => 1,
                'key' => 'irrelevant-because-the-timestamp-is-invalid',
                'fetchedAt' => time() + 3600,
            ], JSON_THROW_ON_ERROR)],
        ];
    }

    public function testTtlRefreshLockAllowsAWorkerToUseAStaleValidatedKey(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('ttl-locked', json_encode([
            'version' => 1,
            'key' => $publicKey,
            'fetchedAt' => time() - 10,
        ], JSON_THROW_ON_ERROR), 3600);
        $cache->set('ttl-locked:ttl-refresh', 'locked-by-another-worker', 30);
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            ttl: 1,
            staleTtl: 3600,
            cache: $cache,
            cacheKey: 'ttl-locked'
        );

        self::assertSame($publicKey, $provider->getPublicKey());
        self::assertCount(0, $http->requests);
    }

    public function testColdCacheDoesNotBypassAnotherWorkersTtlCooldown(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('cold-locked:ttl-refresh', 'locked-by-another-worker', 30);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cold-locked'
        );

        try {
            $provider->getPublicKey();
            self::fail('A cold-cache worker bypassed the shared refresh cooldown.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('rate limited', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testColdCacheDoesNotBypassAnotherWorkersInFlightRefresh(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('cold-refreshing:refresh-lock', 'locked-by-another-worker', 30);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cold-refreshing'
        );

        try {
            $provider->getPublicKey();
            self::fail('A cold-cache worker bypassed the shared in-flight lock.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('in progress', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheFailureFallsBackToProcessLocalRefreshCooldown(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new class implements PublicKeyCacheInterface {
            public function get(string $key): ?string
            {
                throw new RuntimeException('Cache unavailable.');
            }

            public function set(string $key, string $value, int $ttl): void
            {
                throw new RuntimeException('Cache unavailable.');
            }

            public function add(string $key, string $value, int $ttl): bool
            {
                throw new RuntimeException('Cache unavailable.');
            }

            public function setIfValue(
                string $conditionKey,
                string $expectedValue,
                string $key,
                string $value,
                int $ttl
            ): bool {
                throw new RuntimeException('Cache unavailable.');
            }

            public function deleteIfValue(string $key, string $expectedValue): bool
            {
                throw new RuntimeException('Cache unavailable.');
            }

            public function delete(string $key): void
            {
                throw new RuntimeException('Cache unavailable.');
            }
        };
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cache-outage'
        );

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $provider->refreshPublicKey();
                self::fail('A refresh bypassed an unavailable coordination cache.');
            } catch (MonoWebhookException $exception) {
                self::assertStringContainsString('no cached key', $exception->getMessage());
            }
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheGetTypeErrorBubblesFromLoadRecord(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = (new FaultInjectingPublicKeyCache())->injectFault(
            'get',
            new \TypeError('Cache get failed with a programming error.')
        );
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cache-get-error'
        );

        try {
            $provider->getPublicKey();
            self::fail('Cache get error was swallowed as operational failure.');
        } catch (\TypeError $exception) {
            self::assertSame('Cache get failed with a programming error.', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheAddTypeErrorBubblesFromAcquireGuard(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = (new FaultInjectingPublicKeyCache())->injectFault(
            'add',
            new \TypeError('Cache add failed with a programming error.')
        );
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cache-add-error'
        );

        try {
            $provider->refreshPublicKey();
            self::fail('Cache add error was converted to operational fallback.');
        } catch (\TypeError $exception) {
            self::assertSame('Cache add failed with a programming error.', $exception->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheSetIfValueTypeErrorBubblesFromFetchAndStore(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = (new FaultInjectingPublicKeyCache())->injectFault(
            'setIfValue',
            new \TypeError('Cache setIfValue failed with a programming error.')
        );
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'cache-setifvalue-error'
        );

        try {
            $provider->refreshPublicKey();
            self::fail('Cache setIfValue error was handled as cache outage.');
        } catch (\TypeError $exception) {
            self::assertSame(
                'Cache setIfValue failed with a programming error.',
                $exception->getMessage()
            );
        }

        self::assertCount(1, $http->requests);
        $this->assertProcessGuardMissing('cache-setifvalue-error:refresh-lock');
    }

    public function testCacheGetTypeErrorFromGuardExistsDuringConcurrentRefresh(): void
    {
        $cache = (new FaultInjectingPublicKeyCache())
            ->seed('guard-exists:refresh-lock', 'locked-by-another-worker', 30)
            ->injectFault(
                'get',
                new \TypeError('Cache get failed with a programming error.'),
                'guard-exists:refresh-lock'
            );
        $http = new FakeHttpClient($this->publicKeyResponse('unused'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'guard-exists'
        );

        try {
            $provider->getPublicKey();
            self::fail('Cache guard check failed to propagate a programming error.');
        } catch (\TypeError $exception) {
            self::assertSame(
                'Cache get failed with a programming error.',
                $exception->getMessage()
            );
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheDeleteIfValueTypeErrorFromInvalidRecordCleanupBubbles(): void
    {
        $cache = (new FaultInjectingPublicKeyCache())
            ->seed('invalid-json', '{', 3600)
            ->injectFault(
                'deleteIfValue',
                new \TypeError('Cache deleteIfValue failed with a programming error.'),
                'invalid-json'
            );
        $http = new FakeHttpClient($this->publicKeyResponse('unused'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'invalid-json'
        );

        try {
            $provider->getPublicKey();
            self::fail('Cache cleanup error was masked and treated as stale cache.');
        } catch (\TypeError $exception) {
            self::assertSame(
                'Cache deleteIfValue failed with a programming error.',
                $exception->getMessage()
            );
        }

        self::assertCount(0, $http->requests);
    }

    public function testCacheDeleteIfValueTypeErrorFromReleaseGuardBubblesAfterProcessLockIsReleased(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = (new FaultInjectingPublicKeyCache())->injectFault(
            'deleteIfValue',
            new \TypeError('Cache deleteIfValue failed with a programming error.'),
            'release-guard:refresh-lock'
        );
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'release-guard'
        );

        try {
            $provider->refreshPublicKey();
            self::fail('Release-guard cache failure was swallowed into success.');
        } catch (\TypeError $exception) {
            self::assertSame(
                'Cache deleteIfValue failed with a programming error.',
                $exception->getMessage()
            );
        }

        $this->assertProcessGuardMissing('release-guard:refresh-lock');
        self::assertCount(1, $http->requests);
    }

    public function testMissingCacheFallsBackToAProcessLocalCacheWithADeprecation(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $deprecations = $this->captureDeprecations(static fn () => $client->webhooks());

        self::assertCount(1, $deprecations);
        self::assertStringContainsString('process-local public-key cache', $deprecations[0]);

        $webhooks = $client->webhooks();
        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertCount(1, $http->requests);

        // The notice is emitted once per process, not once per client.
        $again = new MonoClient('token', 'https://example.test/api/merchant', new FakeHttpClient());
        self::assertCount(0, $this->captureDeprecations(static fn () => $again->webhooks()));
    }

    public function testASecondClientForTheSameMerchantReusesTheProcessSharedKey(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);

        $firstHttp = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $first = new MonoClient('token', 'https://example.test/api/merchant', $firstHttp);
        self::assertTrue($first->webhooks()->verify($payload, $signature));
        self::assertCount(1, $firstHttp->requests);

        // A long-lived worker constructs a fresh client for the same merchant within the
        // 30-second refresh cooldown. It must reuse the key rather than fail closed, and
        // it must not issue another /pubkey request.
        $secondHttp = new FakeHttpClient();
        $second = new MonoClient('token', 'https://example.test/api/merchant', $secondHttp);
        self::assertTrue($second->webhooks()->verify($payload, $signature));
        self::assertTrue($second->webhooks()->parseVerifiedPayload($payload, $signature)->isSuccessful());
        self::assertCount(0, $secondHttp->requests);

        $legacyHttp = new FakeHttpClient();
        $legacyClient = new MonoClient('token', 'https://example.test/api/merchant', $legacyHttp);
        $legacy = new WebhookService($legacyClient->pubkey());
        self::assertTrue($legacy->verify($payload, $signature));
        self::assertCount(0, $legacyHttp->requests);
    }

    public function testFallbackKeysOfDifferentMerchantsAreIsolated(): void
    {
        [$privateKeyA, $publicKeyA] = $this->keyPair();
        [$privateKeyB, $publicKeyB] = $this->keyPair();
        $payload = $this->payload();
        $signatureA = $this->sign($payload, $privateKeyA);
        $signatureB = $this->sign($payload, $privateKeyB);

        $httpA = new FakeHttpClient(
            $this->publicKeyResponse($publicKeyA),
            $this->publicKeyResponse($publicKeyA)
        );
        $httpB = new FakeHttpClient(
            $this->publicKeyResponse($publicKeyB),
            $this->publicKeyResponse($publicKeyB)
        );
        $clientA = new MonoClient('token-a', 'https://example.test/api/merchant', $httpA);
        $clientB = new MonoClient('token-b', 'https://example.test/api/merchant', $httpB);

        self::assertTrue($clientA->webhooks()->verify($payload, $signatureA));
        self::assertTrue($clientB->webhooks()->verify($payload, $signatureB));
        self::assertCount(1, $httpA->requests);
        self::assertCount(1, $httpB->requests);

        // Merchant B's client must not accept a signature made for merchant A's key.
        // A mismatch triggers the single rotation-recovery refresh, which must hit
        // that merchant's own /pubkey endpoint and still reject the foreign signature.
        self::assertFalse($clientB->webhooks()->verify($payload, $signatureA));
        self::assertFalse($clientA->webhooks()->verify($payload, $signatureB));
        self::assertCount(2, $httpA->requests);
        self::assertCount(2, $httpB->requests);
        self::assertTrue($clientA->webhooks()->verify($payload, $signatureA));
        self::assertTrue($clientB->webhooks()->verify($payload, $signatureB));

        // The same merchant on a different base URL gets its own key slot as well.
        $httpOther = new FakeHttpClient($this->publicKeyResponse($publicKeyB));
        $other = new MonoClient('token-a', 'https://sandbox.example.test/api/merchant', $httpOther);
        self::assertTrue($other->webhooks()->verify($payload, $signatureB));
        self::assertCount(1, $httpOther->requests);
    }

    public function testDirectWebhookServiceWithoutAProviderFailsClosed(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $webhooks = new WebhookService();

        try {
            $webhooks->verify($payload, $signature);
            self::fail('Automatic public-key fetching was enabled without a provider.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('PublicKeyProviderInterface', $exception->getMessage());
        }

        self::assertTrue($webhooks->verify($payload, $signature, $publicKey));
    }

    /**
     * @dataProvider legacyConstructorFormProvider
     * @param callable(PubkeyService, WebhookVerifier): WebhookService $construct
     */
    public function testEveryOneXConstructorFormStillVerifiesWebhooks(callable $construct, string $form): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $webhooks = null;
        $deprecations = $this->captureDeprecations(
            static function () use ($construct, $client, &$webhooks): void {
                $webhooks = $construct($client->pubkey(), new WebhookVerifier());
            }
        );

        self::assertCount(1, $deprecations, $form);
        self::assertStringContainsString('WebhookService::create', $deprecations[0]);
        self::assertInstanceOf(WebhookService::class, $webhooks);
        self::assertTrue($webhooks->verify($payload, $signature), $form);
        self::assertSame('p2_1', $webhooks->parseVerifiedPayload($payload, $signature)->invoiceId());
        self::assertCount(1, $http->requests);
    }

    /** @return array<string, array{callable(PubkeyService, WebhookVerifier): WebhookService, string}> */
    public function legacyConstructorFormProvider(): array
    {
        return [
            'positional' => [
                static fn (PubkeyService $p, WebhookVerifier $v): WebhookService => new WebhookService($p, $v),
                'new WebhookService($pubkey, $verifier)',
            ],
            'positional without verifier' => [
                static fn (PubkeyService $p, WebhookVerifier $v): WebhookService => new WebhookService($p),
                'new WebhookService($pubkey)',
            ],
            'named' => [
                static fn (PubkeyService $p, WebhookVerifier $v): WebhookService => new WebhookService(
                    pubkeyService: $p,
                    verifier: $v
                ),
                'new WebhookService(pubkeyService: $pubkey, verifier: $verifier)',
            ],
            'named, reversed order' => [
                static fn (PubkeyService $p, WebhookVerifier $v): WebhookService => new WebhookService(
                    verifier: $v,
                    pubkeyService: $p
                ),
                'new WebhookService(verifier: $verifier, pubkeyService: $pubkey)',
            ],
            'mixed' => [
                static fn (PubkeyService $p, WebhookVerifier $v): WebhookService => new WebhookService(
                    $p,
                    verifier: $v
                ),
                'new WebhookService($pubkey, verifier: $verifier)',
            ],
        ];
    }

    public function testFactoryAndConstructorRejectConflictingProviders(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider($client->pubkey(), cache: new ArrayPublicKeyCache());

        $created = WebhookService::create($provider, new WebhookVerifier());
        self::assertCount(0, $this->captureDeprecations(static fn () => WebhookService::create($provider)));
        self::assertTrue($created->verify($payload, $signature));
        self::assertTrue((new WebhookService(publicKeyProvider: $provider))->verify($payload, $signature));

        $this->expectException(\InvalidArgumentException::class);
        new WebhookService($client->pubkey(), null, $provider);
    }

    /**
     * @return string[]
     */
    private function captureDeprecations(callable $callback): array
    {
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            if ($errno === E_USER_DEPRECATED) {
                $messages[] = $message;
            }

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    public function testCustomProviderWorksWithoutACache(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $provider = new class ($publicKey) implements PublicKeyProviderInterface {
            private string $publicKey;

            public function __construct(string $publicKey)
            {
                $this->publicKey = $publicKey;
            }

            public function getPublicKey(): string
            {
                return $this->publicKey;
            }

            public function refreshPublicKey(): string
            {
                return $this->publicKey;
            }
        };
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient(
            'token',
            'https://example.test/api/merchant',
            $http,
            publicKeyProvider: $provider
        ))->webhooks();

        self::assertSame($publicKey, $webhooks->getPublicKey());
        self::assertSame($publicKey, $webhooks->refreshPublicKey());
        self::assertTrue($webhooks->verify($payload, $signature));
        self::assertCount(0, $http->requests);
    }

    public function testDirectProvidersDeriveCredentialAndBaseUrlScopedCacheKeys(): void
    {
        [, $firstPublicKey] = $this->keyPair();
        [, $secondPublicKey] = $this->keyPair();
        [, $thirdPublicKey] = $this->keyPair();
        $firstHttp = new FakeHttpClient($this->publicKeyResponse($firstPublicKey));
        $secondHttp = new FakeHttpClient($this->publicKeyResponse($secondPublicKey));
        $thirdHttp = new FakeHttpClient($this->publicKeyResponse($thirdPublicKey));
        $first = new MonoClient('shared-token', 'https://first.example/api/merchant', $firstHttp);
        $second = new MonoClient('shared-token', 'https://second.example/api/merchant', $secondHttp);
        $third = new MonoClient('other-token', 'https://first.example/api/merchant', $thirdHttp);
        $cache = new ArrayPublicKeyCache();
        $firstProvider = new CachedPublicKeyProvider($first->pubkey(), cache: $cache);
        $secondProvider = new CachedPublicKeyProvider($second->pubkey(), cache: $cache);
        $thirdProvider = new CachedPublicKeyProvider($third->pubkey(), cache: $cache);

        self::assertSame($firstPublicKey, $firstProvider->getPublicKey());
        self::assertSame($secondPublicKey, $secondProvider->getPublicKey());
        self::assertSame($thirdPublicKey, $thirdProvider->getPublicKey());
        self::assertCount(1, $firstHttp->requests);
        self::assertCount(1, $secondHttp->requests);
        self::assertCount(1, $thirdHttp->requests);

        $firstFingerprint = $first->pubkey()->cacheKeyFingerprint();
        $secondFingerprint = $second->pubkey()->cacheKeyFingerprint();
        $thirdFingerprint = $third->pubkey()->cacheKeyFingerprint();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstFingerprint);
        self::assertNotSame($firstFingerprint, $secondFingerprint);
        self::assertNotSame($firstFingerprint, $thirdFingerprint);

        $cacheKey = $this->privateProperty(CachedPublicKeyProvider::class, 'cacheKey');
        foreach (
            [
                [$firstProvider, $firstFingerprint],
                [$secondProvider, $secondFingerprint],
                [$thirdProvider, $thirdFingerprint],
            ] as [$provider, $fingerprint]
        ) {
            $derivedKey = $cacheKey->getValue($provider);
            self::assertIsString($derivedKey);
            self::assertStringContainsString('{' . $fingerprint . '}', $derivedKey);
            self::assertStringNotContainsString('shared-token', $derivedKey);
            self::assertStringNotContainsString('other-token', $derivedKey);
            self::assertStringNotContainsString('first.example', $derivedKey);
            self::assertStringNotContainsString('second.example', $derivedKey);
        }
    }

    public function testExpiredLockOwnerCannotDeleteReplacementOrAllowAThirdOwner(): void
    {
        $cache = new ArrayPublicKeyCache();
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $first = new CachedPublicKeyProvider($client->pubkey(), cache: $cache, cacheKey: 'owner-safe');
        $second = new CachedPublicKeyProvider($client->pubkey(), cache: $cache, cacheKey: 'owner-safe');
        $third = new CachedPublicKeyProvider($client->pubkey(), cache: $cache, cacheKey: 'owner-safe');
        $acquire = $this->privateMethod(CachedPublicKeyProvider::class, 'acquireGuard');
        $release = $this->privateMethod(CachedPublicKeyProvider::class, 'releaseGuard');
        $firstOwner = $acquire->invoke($first, ':refresh-lock', 1);
        self::assertIsString($firstOwner);

        usleep(1100000);
        $secondOwner = $acquire->invoke($second, ':refresh-lock', 60);
        self::assertIsString($secondOwner);
        $release->invoke($first, ':refresh-lock', $firstOwner);

        self::assertSame($secondOwner, $cache->get('owner-safe:refresh-lock'));
        self::assertNull($acquire->invoke($third, ':refresh-lock', 60));
        $release->invoke($second, ':refresh-lock', $secondOwner);
        self::assertNull($cache->get('owner-safe:refresh-lock'));
    }

    public function testExpiredLockOwnerCannotPublishAfterAReplacementOwnerAcquiresTheLease(): void
    {
        [, $expiredOwnerPublicKey] = $this->keyPair();
        [, $replacementPublicKey] = $this->keyPair();
        $replacementRecord = json_encode([
            'version' => 1,
            'key' => $replacementPublicKey,
            'fetchedAt' => time(),
        ], JSON_THROW_ON_ERROR);
        $cache = new ArrayPublicKeyCache();
        $http = new FakeHttpClient($this->publicKeyResponse($expiredOwnerPublicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $expiredProvider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'publish-owner-safe'
        );
        $replacementProvider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'publish-owner-safe'
        );
        $acquire = $this->privateMethod(CachedPublicKeyProvider::class, 'acquireGuard');
        $release = $this->privateMethod(CachedPublicKeyProvider::class, 'releaseGuard');
        $fetchAndStore = $this->privateMethod(CachedPublicKeyProvider::class, 'fetchAndStore');
        $expiredOwner = $acquire->invoke($expiredProvider, ':refresh-lock', 1);
        self::assertIsString($expiredOwner);

        usleep(1100000);
        $replacementOwner = $acquire->invoke($replacementProvider, ':refresh-lock', 60);
        self::assertIsString($replacementOwner);
        $cache->set('publish-owner-safe', $replacementRecord, 60);

        self::assertSame(
            $replacementPublicKey,
            $fetchAndStore->invoke($expiredProvider, time(), $expiredOwner)
        );
        self::assertSame($replacementRecord, $cache->get('publish-owner-safe'));
        self::assertCount(1, $http->requests);

        $localRecord = $this->privateProperty(CachedPublicKeyProvider::class, 'localRecord');
        self::assertSame($replacementPublicKey, $localRecord->getValue($expiredProvider)['key'] ?? null);

        $release->invoke($expiredProvider, ':refresh-lock', $expiredOwner);
        self::assertSame($replacementOwner, $cache->get('publish-owner-safe:refresh-lock'));
        $release->invoke($replacementProvider, ':refresh-lock', $replacementOwner);
    }

    public function testLostRefreshLeaseWithoutAWinnerFailsClosed(): void
    {
        [, $losingPublicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $http = new FakeHttpClient($this->publicKeyResponse($losingPublicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'lost-owner'
        );
        $acquire = $this->privateMethod(CachedPublicKeyProvider::class, 'acquireGuard');
        $fetchAndStore = $this->privateMethod(CachedPublicKeyProvider::class, 'fetchAndStore');
        $owner = $acquire->invoke($provider, ':refresh-lock', 60);
        self::assertIsString($owner);
        $cache->set('lost-owner:refresh-lock', 'replacement-owner', 60);

        try {
            $fetchAndStore->invoke($provider, time(), $owner);
            self::fail('A provider returned a key after losing its refresh lease.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('lease expired', $exception->getMessage());
        }

        self::assertNull($cache->get('lost-owner'));
        $localRecord = $this->privateProperty(CachedPublicKeyProvider::class, 'localRecord');
        self::assertNull($localRecord->getValue($provider));
        self::assertCount(1, $http->requests);
    }

    public function testLostRefreshLeaseDoesNotReturnAnExpiredLocalRecord(): void
    {
        [, $expiredPublicKey] = $this->keyPair();
        [, $losingPublicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $http = new FakeHttpClient($this->publicKeyResponse($losingPublicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            ttl: 1,
            minimumRefreshInterval: 1,
            staleTtl: 1,
            cacheKey: 'expired-local-owner'
        );
        $localRecord = $this->privateProperty(CachedPublicKeyProvider::class, 'localRecord');
        $localRecord->setValue($provider, [
            'key' => $expiredPublicKey,
            'fetchedAt' => time() - 2,
        ]);
        $acquire = $this->privateMethod(CachedPublicKeyProvider::class, 'acquireGuard');
        $fetchAndStore = $this->privateMethod(CachedPublicKeyProvider::class, 'fetchAndStore');
        $owner = $acquire->invoke($provider, ':refresh-lock', 120);
        self::assertIsString($owner);
        $cache->set('expired-local-owner:refresh-lock', 'replacement-owner', 120);

        try {
            $fetchAndStore->invoke($provider, time(), $owner);
            self::fail('An expired local key was returned after the refresh lease was lost.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('lease expired', $exception->getMessage());
        }

        self::assertNull($cache->get('expired-local-owner'));
        self::assertSame($expiredPublicKey, $localRecord->getValue($provider)['key'] ?? null);
        self::assertNotSame($losingPublicKey, $localRecord->getValue($provider)['key'] ?? null);
        self::assertCount(1, $http->requests);
    }

    public function testInvalidRecordCompareDeletePreservesAConcurrentReplacement(): void
    {
        [, $publicKey] = $this->keyPair();
        $replacement = json_encode([
            'version' => 1,
            'key' => $publicKey,
            'fetchedAt' => time(),
        ], JSON_THROW_ON_ERROR);
        $cache = new class ('compare-delete', $replacement) implements PublicKeyCacheInterface {
            private ArrayPublicKeyCache $cache;
            private string $recordKey;
            private string $replacement;
            private bool $replacementPublished = false;

            public function __construct(string $recordKey, string $replacement)
            {
                $this->cache = new ArrayPublicKeyCache();
                $this->recordKey = $recordKey;
                $this->replacement = $replacement;
                $this->cache->set($recordKey, '{', 3600);
            }

            public function get(string $key): ?string
            {
                return $this->cache->get($key);
            }

            public function set(string $key, string $value, int $ttl): void
            {
                $this->cache->set($key, $value, $ttl);
            }

            public function add(string $key, string $value, int $ttl): bool
            {
                return $this->cache->add($key, $value, $ttl);
            }

            public function setIfValue(
                string $conditionKey,
                string $expectedValue,
                string $key,
                string $value,
                int $ttl
            ): bool {
                return $this->cache->setIfValue($conditionKey, $expectedValue, $key, $value, $ttl);
            }

            public function deleteIfValue(string $key, string $expectedValue): bool
            {
                if ($key === $this->recordKey && !$this->replacementPublished) {
                    $this->cache->set($key, $this->replacement, 3600);
                    $this->replacementPublished = true;
                }

                return $this->cache->deleteIfValue($key, $expectedValue);
            }

            public function delete(string $key): void
            {
                $this->cache->delete($key);
            }
        };
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            cache: $cache,
            cacheKey: 'compare-delete'
        );

        self::assertSame($publicKey, $provider->getPublicKey());
        self::assertSame($replacement, $cache->get('compare-delete'));
        self::assertCount(0, $http->requests);
    }

    public function testSharedCooldownPreventsASecondProviderRefresh(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $http = new FakeHttpClient($this->publicKeyResponse($publicKey));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $first = new CachedPublicKeyProvider($client->pubkey(), cache: $cache, cacheKey: 'shared-key');
        $second = new CachedPublicKeyProvider($client->pubkey(), cache: $cache, cacheKey: 'shared-key');

        self::assertSame($publicKey, $first->refreshPublicKey());
        self::assertSame($publicKey, $second->refreshPublicKey());
        self::assertCount(1, $http->requests);
    }

    public function testRefreshFailureReturnsAStaleKey(): void
    {
        [, $publicKey] = $this->keyPair();
        $cache = new ArrayPublicKeyCache();
        $cache->set('stale-refresh', json_encode([
            'version' => 1,
            'key' => $publicKey,
            'fetchedAt' => time() - 10,
        ], JSON_THROW_ON_ERROR), 3600);
        $http = new FakeHttpClient(new HttpResponse(503, '{}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            ttl: 1,
            minimumRefreshInterval: 1,
            staleTtl: 3600,
            cache: $cache,
            cacheKey: 'stale-refresh'
        );

        self::assertSame($publicKey, $provider->refreshPublicKey());
    }

    public function testRefreshFailureWithoutAStaleKeyIsPropagated(): void
    {
        $http = new FakeHttpClient(new HttpResponse(503, '{}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);
        $provider = new CachedPublicKeyProvider(
            $client->pubkey(),
            minimumRefreshInterval: 1,
            cache: new ArrayPublicKeyCache()
        );

        $this->expectException(\Vladchornyi\Mono\Exceptions\MonoApiException::class);
        $provider->refreshPublicKey();
    }

    public function testVerifierRejectsEmptyPayloadAndSignature(): void
    {
        $verifier = new WebhookVerifier();

        try {
            $verifier->assertPayload('');
            self::fail('Empty payload was accepted.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('empty', $exception->getMessage());
        }

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('missing');
        $verifier->assertSignature('');
    }

    /**
     * @dataProvider invalidPublicKeyProvider
     */
    public function testVerifierRejectsMalformedPublicKeys(string $publicKey): void
    {
        $this->expectException(MonoWebhookException::class);
        (new WebhookVerifier())->assertPublicKey($publicKey);
    }

    /** @return array<string, array{string}> */
    public function invalidPublicKeyProvider(): array
    {
        return [
            'not base64' => ['***'],
            'not pem' => [base64_encode('not a PEM key')],
        ];
    }

    public function testVerifierAcceptsCanonicalLongFormDerStructure(): void
    {
        $integer = "\x02\x40\x01" . str_repeat("\x00", 63);
        $signature = "\x30\x81\x84" . $integer . $integer;

        (new WebhookVerifier())->assertSignature(base64_encode($signature));
        self::assertTrue(true);
    }

    public function testParserRejectsNonObjectPayloads(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        foreach (
            ['[]', '[1, 2]', '"text"', '1', 'true', 'null'] as $payload
        ) {
            try {
                $webhooks->parse($payload);
                self::fail('Invalid webhook JSON was accepted: ' . $payload);
            } catch (MonoWebhookException $exception) {
                self::assertSame(
                    'Webhook JSON payload must be an object.',
                    $exception->getMessage(),
                    $payload
                );
            }
        }
    }

    public function testParserRejectsEmptyObjectPayload(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('Webhook JSON payload must be a non-empty object.');
        $webhooks->parse('{}');
    }

    public function testParserAcceptsLeadingWhitespaceAndNestedObjectsAsArrays(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();
        $payload = "\n\t {\n  \"invoiceId\": \"p2_1\",\n  \"meta\": {\"nested\": {\"depth\": 1}}\n}";

        self::assertSame(
            [
                'invoiceId' => 'p2_1',
                'meta' => ['nested' => ['depth' => 1]],
            ],
            $webhooks->parse($payload)
        );
    }

    public function testParserRejectsMalformedJsonWithChainedJsonException(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        try {
            $webhooks->parse('{');
            self::fail('Malformed webhook JSON was accepted.');
        } catch (MonoWebhookException $exception) {
            self::assertSame(
                'Failed to decode webhook JSON: Syntax error',
                $exception->getMessage(),
                'Expected strict decode error with chained cause.'
            );
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
            self::assertSame('Syntax error', $exception->getPrevious()?->getMessage());
        }
    }

    public function testMissingSignatureAndInvalidVerifiedSignatureAreRejected(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        try {
            $webhooks->verify($this->payload(), null);
            self::fail('Missing signature was accepted.');
        } catch (MonoWebhookException $exception) {
            self::assertStringContainsString('missing', $exception->getMessage());
        }

        $invalidSignature = $this->sign('{"different":true}', $privateKey);
        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('signature is invalid');
        $webhooks->parseVerified($this->payload(), $invalidSignature, $publicKey);
    }

    public function testTypedPayloadConvenienceMethodsUseTheSameVerifiedData(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $payload = $this->payload();
        $signature = $this->sign($payload, $privateKey);
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        $fromArray = $webhooks->payload(['invoiceId' => 'p2_array']);
        $parsed = $webhooks->parsePayload($payload);
        $verified = $webhooks->parseVerifiedPayload($payload, $signature, $publicKey);

        self::assertSame('p2_array', $fromArray->invoiceId());
        self::assertSame('p2_1', $parsed->invoiceId());
        self::assertSame($parsed->toArray(), $verified->toArray());
        self::assertTrue($webhooks->shouldApplyPayload($verified));
        self::assertTrue($webhooks->shouldApplyPayload(
            $verified,
            new DateTimeImmutable('2026-07-01T09:59:59Z')
        ));
    }

    public function testModifiedDateCoversMissingTypedAndValidValues(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        self::assertNull($webhooks->getModifiedDate([]));
        self::assertSame(
            '2026-07-01T10:00:00+00:00',
            $webhooks->getModifiedDate(['modifiedDate' => '2026-07-01T10:00:00Z'])?->format(DATE_ATOM)
        );

        $this->expectException(MonoWebhookException::class);
        $this->expectExceptionMessage('must be an RFC3339 string');
        $webhooks->getModifiedDate(['modifiedDate' => 123]);
    }

    /**
     * @dataProvider stalenessProvider
     * @param DateTimeImmutable|Instant|string|null $stored
     */
    public function testShouldApplyOrdersEventsExactly(
        ?string $incoming,
        $stored,
        bool $allowMissing,
        bool $expected,
        string $case
    ): void {
        $webhooks = new WebhookService();
        $payload = ['invoiceId' => 'p2_1'];

        if ($incoming !== null) {
            $payload['modifiedDate'] = $incoming;
        }

        self::assertSame($expected, $webhooks->shouldApply($payload, $stored, $allowMissing), $case);
        self::assertSame(
            $expected,
            $webhooks->shouldApplyPayload(WebhookPayload::fromArray($payload), $stored, $allowMissing),
            $case . ' (typed payload)'
        );
    }

    /** @return array<string, array{string|null, DateTimeImmutable|Instant|string|null, bool, bool, string}> */
    public function stalenessProvider(): array
    {
        $base = '2026-07-01T10:00:00Z';
        $nano100 = '2026-07-01T10:00:00.123456100Z';
        $nano900 = '2026-07-01T10:00:00.123456900Z';
        $microDate = new DateTimeImmutable('2026-07-01T10:00:00.123456Z');

        return [
            'missing incoming, nothing stored, allowed' => [null, null, true, true, 'first event without date'],
            'missing incoming, nothing stored, rejected' => [null, null, false, false, 'reject without stored'],
            'missing incoming, stored, allowed' => [null, $base, true, true, 'tokenization callback'],
            'missing incoming, stored, rejected' => [null, $base, false, false, 'explicit rejection'],
            'first event with date' => [$base, null, false, true, 'nothing stored yet'],
            'identical' => [$base, $base, true, false, 'same instant is a replay'],
            'identical via DateTime' => [$base, new DateTimeImmutable($base), true, false, 'same as DateTime'],
            'identical via Instant' => [$base, Instant::parse($base), true, false, 'same as Instant'],
            'older by a second' => ['2026-07-01T09:59:59Z', $base, true, false, 'older'],
            'newer by a second' => ['2026-07-01T10:00:01Z', $base, true, true, 'newer'],
            'same instant, other zone' => ['2026-07-01T13:00:00+03:00', $base, true, false, 'zone equivalence'],
            'newer in other zone' => ['2026-07-01T13:00:01+03:00', $base, true, true, 'zone newer'],
            'older in other zone' => ['2026-07-01T04:59:59-05:00', $base, true, false, 'zone older'],
            'micro older than stored nanos' => ['2026-07-01T10:00:00.123456Z', $nano100, true, false, 'micro < nano'],
            'nanosecond newer' => [$nano900, $nano100, true, true, '800 ns later'],
            'nanosecond older' => [$nano100, $nano900, true, false, '800 ns earlier'],
            'nanosecond equal, trailing zeros' => ['2026-07-01T10:00:00.1234561Z', $nano100, true, false, 'same'],
            'nanos vs DateTime, same µs' => [$nano900, $microDate, true, false, 'DateTime compares at µs'],
            'nanos vs DateTime, next µs' => ['2026-07-01T10:00:00.123457Z', $microDate, true, true, '1 µs later'],
            'nanos vs DateTime, previous µs' => ['2026-07-01T10:00:00.123455999Z', $microDate, true, false, 'older'],
        ];
    }

    public function testStoredTimestampMustBeValidWhenUsedForOrdering(): void
    {
        $webhooks = new WebhookService();

        self::assertSame(
            '2026-07-01T10:00:00.123456789Z',
            (string) $webhooks->getModifiedInstant(['modifiedDate' => '2026-07-01T13:00:00.123456789+03:00'])
        );
        self::assertSame(
            '123456',
            $webhooks->getModifiedDate(['modifiedDate' => '2026-07-01T10:00:00.123456789Z'])?->format('u')
        );

        $this->expectException(\InvalidArgumentException::class);
        $webhooks->shouldApply(['invoiceId' => 'p2_1', 'modifiedDate' => '2026-07-01T10:00:00Z'], 'yesterday');
    }

    public function testModifiedDateMustBeStrictRfc3339(): void
    {
        $http = new FakeHttpClient();
        $webhooks = (new MonoClient('token', 'https://example.test/api/merchant', $http))->webhooks();

        $this->expectException(MonoWebhookException::class);
        $webhooks->getModifiedDate(['modifiedDate' => 'tomorrow']);
    }

    private function privateProperty(string $class, string $name): \ReflectionProperty
    {
        $property = new \ReflectionProperty($class, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property;
    }

    private function privateMethod(string $class, string $name): \ReflectionMethod
    {
        $method = new \ReflectionMethod($class, $name);
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method;
    }

    private function payload(): string
    {
        return '{"invoiceId":"p2_1","status":"success","modifiedDate":"2026-07-01T10:00:00Z"}';
    }

    /**
     * @return array{resource|\OpenSSLAsymmetricKey, string}
     */
    private function keyPair(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return [$key, base64_encode($details['key'])];
    }

    /**
     * @param resource|\OpenSSLAsymmetricKey $privateKey
     */
    private function sign(string $payload, $privateKey): string
    {
        self::assertTrue(openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return base64_encode($signature);
    }

    private function publicKeyResponse(string $publicKey): HttpResponse
    {
        return new HttpResponse(200, json_encode(['key' => $publicKey], JSON_THROW_ON_ERROR));
    }
}
