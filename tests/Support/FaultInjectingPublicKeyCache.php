<?php

namespace Vladchornyi\Mono\Tests\Support;

use Throwable;
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;

final class FaultInjectingPublicKeyCache implements PublicKeyCacheInterface
{
    private ArrayPublicKeyCache $cache;

    /** @var array<string, array{error: Throwable, key: ?string}> */
    private array $operationFaults = [];

    public function __construct()
    {
        $this->cache = new ArrayPublicKeyCache();
    }

    public function injectFault(string $operation, Throwable $error, ?string $key = null): self
    {
        $this->operationFaults[$operation] = ['error' => $error, 'key' => $key];

        return $this;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $this->throwIfFault('set', $key);
        $this->cache->set($key, $value, $ttl);
    }

    public function get(string $key): ?string
    {
        $this->throwIfFault('get', $key);
        return $this->cache->get($key);
    }

    public function add(string $key, string $value, int $ttl): bool
    {
        $this->throwIfFault('add', $key);
        return $this->cache->add($key, $value, $ttl);
    }

    public function setIfValue(
        string $conditionKey,
        string $expectedValue,
        string $key,
        string $value,
        int $ttl
    ): bool {
        $this->throwIfFault('setIfValue', $conditionKey);
        return $this->cache->setIfValue($conditionKey, $expectedValue, $key, $value, $ttl);
    }

    public function deleteIfValue(string $key, string $expectedValue): bool
    {
        $this->throwIfFault('deleteIfValue', $key);
        return $this->cache->deleteIfValue($key, $expectedValue);
    }

    public function delete(string $key): void
    {
        $this->throwIfFault('delete', $key);
        $this->cache->delete($key);
    }

    public function seed(string $key, string $value, int $ttl = 3600): self
    {
        $this->cache->set($key, $value, $ttl);

        return $this;
    }

    private function throwIfFault(string $operation, string $key): void
    {
        if (!isset($this->operationFaults[$operation])) {
            return;
        }

        $fault = $this->operationFaults[$operation];
        if ($fault['key'] === null || $fault['key'] === $key) {
            throw $fault['error'];
        }
    }
}
