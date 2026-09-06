<?php

namespace Vladchornyi\Mono\Webhooks;

use InvalidArgumentException;
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;
use Vladchornyi\Mono\Contracts\PublicKeyProviderInterface;
use Vladchornyi\Mono\Exceptions\MonoException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Services\PubkeyService;

final class CachedPublicKeyProvider implements PublicKeyProviderInterface
{
    private const RECORD_VERSION = 1;
    private const DEFAULT_CACHE_KEY = 'vladchornyi-mono:webhook-public-key';
    private const REFRESH_LOCK_SUFFIX = ':refresh-lock';
    private const TTL_REFRESH_COOLDOWN_SUFFIX = ':ttl-refresh';
    private const FORCED_REFRESH_COOLDOWN_SUFFIX = ':refresh-cooldown';
    private const MINIMUM_REFRESH_LOCK_TTL = 120;
    private const DEFAULT_REFRESH_LOCK_TTL = self::MINIMUM_REFRESH_LOCK_TTL;

    /** @var array<string, array{expiresAt: float, owner: string, shared: bool}> */
    private static array $processGuards = [];

    private PubkeyService $pubkeyService;
    private PublicKeyCacheInterface $cache;
    private WebhookVerifier $verifier;
    private int $ttl;
    private int $minimumRefreshInterval;
    private int $staleTtl;
    private int $refreshLockTtl;
    private int $refreshWaitMilliseconds;
    private int $refreshPollMilliseconds;
    private string $cacheKey;

    /** @var array{key: string, fetchedAt: int}|null */
    private ?array $localRecord = null;

    public function __construct(
        PubkeyService $pubkeyService,
        PublicKeyCacheInterface $cache,
        int $ttl = 3600,
        int $minimumRefreshInterval = 30,
        int $staleTtl = 86400,
        string $cacheKey = self::DEFAULT_CACHE_KEY,
        ?WebhookVerifier $verifier = null,
        int $refreshLockTtl = self::DEFAULT_REFRESH_LOCK_TTL,
        int $refreshWaitMilliseconds = 250,
        int $refreshPollMilliseconds = 10
    ) {
        if ($ttl <= 0 || $minimumRefreshInterval <= 0 || $staleTtl < $ttl) {
            throw new InvalidArgumentException(
                'Public-key TTL must be positive, stale TTL must be at least the TTL, '
                . 'and refresh interval must be positive.'
            );
        }

        if (trim($cacheKey) === '') {
            throw new InvalidArgumentException('Public-key cache key must not be empty.');
        }

        if ($refreshLockTtl < self::MINIMUM_REFRESH_LOCK_TTL) {
            throw new InvalidArgumentException(
                'Public-key refresh lock TTL must be at least '
                . self::MINIMUM_REFRESH_LOCK_TTL
                . ' seconds.'
            );
        }

        if ($refreshWaitMilliseconds <= 0 || $refreshPollMilliseconds <= 0) {
            throw new InvalidArgumentException(
                'Public-key refresh wait and poll intervals must be positive milliseconds.'
            );
        }

        if ($refreshPollMilliseconds > $refreshWaitMilliseconds) {
            throw new InvalidArgumentException(
                'Public-key refresh poll interval must not exceed refresh wait interval.'
            );
        }

        $this->pubkeyService = $pubkeyService;
        $this->ttl = $ttl;
        $this->minimumRefreshInterval = $minimumRefreshInterval;
        $this->staleTtl = $staleTtl;
        $this->refreshLockTtl = $refreshLockTtl;
        $this->refreshWaitMilliseconds = $refreshWaitMilliseconds;
        $this->refreshPollMilliseconds = $refreshPollMilliseconds;
        $this->cache = $cache;
        $this->cacheKey = $cacheKey === self::DEFAULT_CACHE_KEY
            ? $cacheKey . ':{' . $pubkeyService->cacheKeyFingerprint() . '}'
            : $cacheKey;
        $this->verifier = $verifier ?? new WebhookVerifier();
    }

    public function getPublicKey(): string
    {
        $now = time();
        $record = $this->loadRecord();

        if ($record !== null && $now - $record['fetchedAt'] < $this->ttl) {
            return $record['key'];
        }

        return $this->refresh($record, $now, false);
    }

    public function refreshPublicKey(): string
    {
        $now = time();
        $record = $this->loadRecord();

        return $this->refresh($record, $now, true);
    }

    /**
     * @param array{key: string, fetchedAt: int}|null $record
     */
    private function refresh(?array $record, int $now, bool $forced): string
    {
        $lock = $this->acquireGuard(self::REFRESH_LOCK_SUFFIX, $this->refreshLockTtl);

        if ($lock === null) {
            return $this->waitForConcurrentRefresh($record, $now, $forced);
        }

        if ($lock === false) {
            return $this->cachedKeyOrThrow($record, $now);
        }

        try {
            $current = $this->loadRecord();

            if (!$forced && $current !== null && $now - $current['fetchedAt'] < $this->ttl) {
                return $current['key'];
            }

            if ($forced && $current !== null && $this->recordChanged($record, $current)) {
                return $current['key'];
            }

            $cooldownSuffix = $forced
                ? self::FORCED_REFRESH_COOLDOWN_SUFFIX
                : self::TTL_REFRESH_COOLDOWN_SUFFIX;

            if (!$this->acquireCooldown($cooldownSuffix)) {
                return $this->cachedKeyOrThrow($current ?? $record, $now);
            }

            try {
                return $this->fetchAndStore($now, $lock);
            } catch (MonoException $exception) {
                return $this->staleKeyOrThrow($current ?? $record, $now, $exception);
            }
        } finally {
            $this->releaseGuard(self::REFRESH_LOCK_SUFFIX, $lock);
        }
    }

    private function fetchAndStore(int $now, string $owner): string
    {
        $response = $this->pubkeyService->get();
        $key = $response['key'] ?? null;

        if (!is_string($key) || $key === '') {
            throw new MonoWebhookException('Monobank public key response does not contain a key.');
        }

        $this->verifier->assertPublicKey($key);

        $cacheRecord = [
            'version' => self::RECORD_VERSION,
            'key' => $key,
            'fetchedAt' => $now,
        ];
        $record = json_encode($cacheRecord, JSON_THROW_ON_ERROR);

        try {
            $published = $this->cache->setIfValue(
                $this->cacheKey . self::REFRESH_LOCK_SUFFIX,
                $owner,
                $this->cacheKey,
                $record,
                $this->staleTtl
            );
        } catch (\Exception $exception) {
            throw new MonoWebhookException(
                'Public-key refresh ownership could not be confirmed.',
                0,
                $exception
            );
        }

        if (!$published) {
            $winner = $this->loadRecord();
            return $this->staleKeyOrThrow(
                $winner,
                time(),
                new MonoWebhookException(
                    'Public-key refresh lease expired before publication and no validated key is available.'
                )
            );
        }

        $this->localRecord = [
            'key' => $key,
            'fetchedAt' => $now,
        ];

        return $key;
    }

    /**
     * @return array{key: string, fetchedAt: int}|null
     */
    private function loadRecord(): ?array
    {
        try {
            $serialized = $this->cache->get($this->cacheKey);
        } catch (\Exception $exception) {
            return $this->localRecord;
        }

        if ($serialized === null) {
            return $this->localRecord;
        }

        try {
            $record = json_decode($serialized, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->deleteInvalidCacheRecord($serialized);
            return $this->localRecord;
        }

        if (
            !is_array($record)
            || ($record['version'] ?? null) !== self::RECORD_VERSION
            || !is_string($record['key'] ?? null)
            || !is_int($record['fetchedAt'] ?? null)
            || $record['fetchedAt'] <= 0
            || $record['fetchedAt'] > time() + 60
        ) {
            $this->deleteInvalidCacheRecord($serialized);
            return $this->localRecord;
        }

        try {
            $this->verifier->assertPublicKey($record['key']);
        } catch (MonoWebhookException $exception) {
            $this->deleteInvalidCacheRecord($serialized);
            return $this->localRecord;
        }

        $this->localRecord = [
            'key' => $record['key'],
            'fetchedAt' => $record['fetchedAt'],
        ];

        return $this->localRecord;
    }

    private function acquireCooldown(string $suffix): bool
    {
        return is_string($this->acquireGuard($suffix, $this->minimumRefreshInterval));
    }

    /**
     * Returns an owner token when the shared cache guard was acquired, false when only
     * the process-local fallback was acquired, and null when another worker
     * already owns the guard.
     */
    private function acquireGuard(string $suffix, int $ttl): string|false|null
    {
        $guardKey = $this->cacheKey . $suffix;
        $now = microtime(true);
        $this->discardExpiredProcessGuard($guardKey, $now);

        if (isset(self::$processGuards[$guardKey])) {
            return null;
        }

        try {
            $owner = bin2hex(random_bytes(16));
            $shared = $this->cache->add($guardKey, $owner, $ttl);
        } catch (\Exception $exception) {
            $owner = hash('sha256', $guardKey . "\0" . $now . "\0" . spl_object_id($this));
            $shared = null;
        }

        if ($shared === false) {
            return null;
        }

        self::$processGuards[$guardKey] = [
            'expiresAt' => $now + $ttl,
            'owner' => $owner,
            'shared' => $shared === true,
        ];

        return $shared === true ? $owner : false;
    }

    private function releaseGuard(string $suffix, string $owner): void
    {
        $guardKey = $this->cacheKey . $suffix;
        $processGuard = self::$processGuards[$guardKey] ?? null;

        if ($processGuard !== null && hash_equals($processGuard['owner'], $owner)) {
            unset(self::$processGuards[$guardKey]);
        }

        try {
            $this->cache->deleteIfValue($guardKey, $owner);
        } catch (\Exception $exception) {
            // The lock has a bounded TTL and will recover without unsafe fetches.
        }
    }

    private function discardExpiredProcessGuard(string $guardKey, float $now): void
    {
        if (
            isset(self::$processGuards[$guardKey])
            && self::$processGuards[$guardKey]['expiresAt'] <= $now
        ) {
            unset(self::$processGuards[$guardKey]);
        }
    }

    /**
     * @param array{key: string, fetchedAt: int}|null $record
     */
    private function waitForConcurrentRefresh(?array $record, int $now, bool $forced): string
    {
        $deadline = microtime(true) + ($this->refreshWaitMilliseconds / 1000);

        do {
            usleep($this->refreshPollMilliseconds * 1000);
            $current = $this->loadRecord();

            if ($current !== null && $this->recordChanged($record, $current)) {
                return $current['key'];
            }

            if (!$this->guardExists(self::REFRESH_LOCK_SUFFIX)) {
                return $this->cachedKeyOrThrow($current ?? $record, $now);
            }
        } while (microtime(true) < $deadline);

        if (!$forced && $record !== null) {
            return $this->staleKeyOrThrow(
                $record,
                $now,
                new MonoWebhookException('Public-key refresh is still in progress.')
            );
        }

        throw new MonoWebhookException('Public-key refresh is still in progress and no refreshed key is available.');
    }

    private function guardExists(string $suffix): bool
    {
        $guardKey = $this->cacheKey . $suffix;
        $now = microtime(true);
        $this->discardExpiredProcessGuard($guardKey, $now);

        if (isset(self::$processGuards[$guardKey])) {
            return self::$processGuards[$guardKey]['shared'];
        }

        try {
            return $this->cache->get($guardKey) !== null;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /**
     * @param array{key: string, fetchedAt: int}|null $previous
     * @param array{key: string, fetchedAt: int}|null $current
     */
    private function recordChanged(?array $previous, ?array $current): bool
    {
        if ($current === null) {
            return false;
        }

        return $previous === null
            || $previous['key'] !== $current['key']
            || $previous['fetchedAt'] !== $current['fetchedAt'];
    }

    /**
     * @param array{key: string, fetchedAt: int}|null $record
     */
    private function cachedKeyOrThrow(?array $record, int $now): string
    {
        return $this->staleKeyOrThrow(
            $record,
            $now,
            new MonoWebhookException('Public-key refresh is rate limited and no cached key is available.')
        );
    }

    private function deleteInvalidCacheRecord(string $serialized): void
    {
        try {
            $this->cache->deleteIfValue($this->cacheKey, $serialized);
        } catch (\Exception $exception) {
            // Ignore cache cleanup failures; the invalid record is never returned.
        }
    }

    /**
     * @param array{key: string, fetchedAt: int}|null $record
     */
    private function staleKeyOrThrow(?array $record, int $now, MonoException $exception): string
    {
        if ($record !== null && $now - $record['fetchedAt'] < $this->staleTtl) {
            return $record['key'];
        }

        throw $exception;
    }
}
