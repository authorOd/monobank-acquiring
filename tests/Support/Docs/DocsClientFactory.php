<?php

namespace Vladchornyi\Mono\Tests\Support\Docs;

use Vladchornyi\Mono\MonoClient;

/**
 * Replacement for `new MonoClient(...)` inside documentation blocks: keeps the
 * example's own arguments but binds the stub transport and key provider so no
 * request can leave the process. Positional and named arguments are accepted.
 */
final class DocsClientFactory
{
    private static string $publicKey = '';

    public static function bind(string $publicKey): void
    {
        self::$publicKey = $publicKey;
    }

    /**
     * @param mixed ...$arguments
     */
    public static function create(...$arguments): MonoClient
    {
        $named = [];
        $positional = ['apiKey', 'baseUrl', 'httpClient', 'httpOptions', 'publicKeyProvider', 'publicKeyCache'];

        foreach ($arguments as $key => $value) {
            $named[is_int($key) ? $positional[$key] : $key] = $value;
        }

        return new MonoClient(
            (string) ($named['apiKey'] ?? 'doc-token'),
            (string) ($named['baseUrl'] ?? 'https://api.monobank.ua/api/merchant'),
            new DocsStubHttpClient(self::$publicKey),
            (array) ($named['httpOptions'] ?? []),
            $named['publicKeyProvider'] ?? new DocsPublicKeyProvider(self::$publicKey),
            $named['publicKeyCache'] ?? null
        );
    }
}
