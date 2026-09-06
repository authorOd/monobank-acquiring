<?php

namespace Vladchornyi\Mono\Contracts;

/**
 * Minimal cache contract used by the webhook public-key provider.
 * Operational cache backends should surface coordination or I/O failures as
 * `Exception` instances (for example runtime connectivity/serialization
 * failures). The webhook provider catches `Exception` only, never `Error`.
 *
 * `get()` and `add()` `Exception` failures are treated as operational outages
 * (fallback or local fallback lock). `setIfValue()` `Exception` failures while
 * publishing are wrapped as a public-key ownership error. `deleteIfValue()`
 * `Exception` failures from cache invalidation and lock release paths are ignored
 * by the provider. `TypeError` and other `Error` values always bubble to the
 * caller unchanged.
 *
 * Implementations backed by Redis or another shared store must make add()
 * and the conditional methods atomic so concurrent workers cannot all refresh
 * the key, release another worker's lease, or publish after losing ownership.
 */
interface PublicKeyCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttl): void;

    /** Atomically stores the value only when the key does not exist. */
    public function add(string $key, string $value, int $ttl): bool;

    /**
     * Atomically stores the value only when the condition key still contains
     * the expected owner token.
     *
     * Distributed stores must execute the comparison and write as one operation.
     * Multi-key cluster adapters must also keep both keys in the same hash slot.
     */
    public function setIfValue(
        string $conditionKey,
        string $expectedValue,
        string $key,
        string $value,
        int $ttl
    ): bool;

    /** Atomically deletes the key only when it still contains the expected value. */
    public function deleteIfValue(string $key, string $expectedValue): bool;

    public function delete(string $key): void;
}
