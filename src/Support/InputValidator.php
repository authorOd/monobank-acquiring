<?php

namespace Vladchornyi\Mono\Support;

use InvalidArgumentException;

final class InputValidator
{
    public static function nonEmptyString(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$name} must not be empty.");
        }
    }

    public static function positiveInt(int $value, string $name): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }
    }

    public static function nonNegativeInt(int $value, string $name): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$name} must be a non-negative integer.");
        }
    }

    public static function rfc3339(string $value, string $name): void
    {
        if (Rfc3339::parse($value) === null) {
            throw new InvalidArgumentException("{$name} must be a valid RFC3339 timestamp.");
        }
    }

    public static function httpUrl(#[\SensitiveParameter] string $value, string $name): void
    {
        $parts = parse_url($value);

        if (
            strlen($value) > 2048
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new InvalidArgumentException("{$name} must be a valid HTTP or HTTPS URL.");
        }
    }

    /**
     * API base URLs receive the merchant token in `X-Token`, so they must use
     * HTTPS. Plain HTTP is accepted only for loopback hosts (`localhost`,
     * `127.0.0.0/8`, `::1`), which local test servers and sidecar proxies use.
     */
    public static function secureBaseUrl(string $value, string $name): void
    {
        self::httpUrl($value, $name);

        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';

        if ($scheme === 'https' || self::isLoopbackHost($host)) {
            return;
        }

        throw new InvalidArgumentException(
            "{$name} must use HTTPS; plain HTTP is accepted only for loopback hosts."
        );
    }

    private static function isLoopbackHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return inet_pton($host) === inet_pton('::1');
        }

        return false;
    }
}
