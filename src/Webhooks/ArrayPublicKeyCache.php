<?php

namespace Vladchornyi\Mono\Webhooks;

use InvalidArgumentException;
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;

/**
 * Process-local cache for tests and single-process applications.
 *
 * Multi-worker deployments must inject a shared atomic cache implementation.
 */
final class ArrayPublicKeyCache implements PublicKeyCacheInterface
{
    private static ?self $processShared = null;

    /** @var array<string, array{value: string, expiresAt: int}> */
    private array $values = [];

    /**
     * One cache instance shared by every fallback provider in this process.
     *
     * CachedPublicKeyProvider rate limits refreshes per process and per
     * cache key (which embeds the API base URL and merchant-token
     * fingerprint). Sharing the record store across MonoClient instances keeps
     * that limit and the cached key in the same scope, so a second client for
     * the same merchant reuses the key instead of failing closed. Different
     * merchants never share a key because their cache keys differ.
     */
    public static function processShared(): self
    {
        return self::$processShared ??= new self();
    }

    public function get(string $key): ?string
    {
        if (!isset($this->values[$key])) {
            return null;
        }

        if ($this->values[$key]['expiresAt'] <= time()) {
            unset($this->values[$key]);
            return null;
        }

        return $this->values[$key]['value'];
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->assertTtl($ttl);

        $this->values[$key] = [
            'value' => $value,
            'expiresAt' => time() + $ttl,
        ];
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        $this->assertTtl($ttl);

        if ($this->get($key) !== null) {
            return false;
        }

        $this->set($key, $value, $ttl);

        return true;
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        if ($this->get($key) !== $expectedValue) {
            return false;
        }

        unset($this->values[$key]);

        return true;
    }

    public function setIfValue(
        string $conditionKey,
        string $expectedValue,
        string $key,
        string $value,
        int $ttl
    ): bool {
        if ($this->get($conditionKey) !== $expectedValue) {
            return false;
        }

        $this->set($key, $value, $ttl);

        return true;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    private function assertTtl(int $ttl): void
    {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('Cache TTL must be a positive integer.');
        }
    }
}
