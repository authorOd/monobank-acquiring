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

    public static function httpUrl(string $value, string $name): void
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
}
