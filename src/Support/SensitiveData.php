<?php

namespace Vladchornyi\Mono\Support;

use InvalidArgumentException;

class SensitiveData
{
    /** @var string[] */
    protected const SENSITIVE_KEYS = [
        'xtoken',
        'xsign',
        'xsignature',
        'token',
        'authorization',
        'apikey',
        'xapikey',
        'clientsecret',
        'accesstoken',
        'refreshtoken',
        'cookie',
        'setcookie',
        'secret',
        'password',
        'privatekey',
        'cardtoken',
        'walletid',
        'tranid',
        'rrn',
        'approvalcode',
        'maskedpan',
        'pan',
        'cardnumber',
        'cvv',
        'email',
        'customeremails',
    ];

    /** @var string[] */
    protected const SAFE_HEADER_KEYS = [
        'accept',
        'acceptencoding',
        'acceptlanguage',
        'cachecontrol',
        'contentlength',
        'contenttype',
        'date',
        'retryafter',
        'server',
        'useragent',
        'xrequestid',
    ];

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function sanitize($value)
    {
        return self::sanitizeValue($value, 0);
    }

    /**
     * Unknown headers are redacted by default. Add only demonstrably safe
     * diagnostic headers via $additionalSafeHeaders; SAFE_HEADER_KEYS contains
     * the built-in allowlist.
     *
     * @param array<string, mixed> $headers
     * @param string[] $additionalSafeHeaders
     * @return array<string, mixed>
     */
    public static function sanitizeHeaders(
        #[\SensitiveParameter] array $headers,
        array $additionalSafeHeaders = []
    ): array {
        $sanitized = [];
        $safeHeaderKeys = self::safeHeaderKeys($additionalSafeHeaders);

        foreach ($headers as $name => $value) {
            $normalized = self::normalizeKey($name);

            if (
                $normalized === null
                || self::isSensitiveKey($name)
                || !array_key_exists($normalized, $safeHeaderKeys)
            ) {
                $sanitized[$name] = '[redacted]';
                continue;
            }

            $sanitized[$name] = self::sanitizeValue($value, 0);
        }

        return $sanitized;
    }

    /**
     * @param string[] $additionalSafeHeaders
     * @return array<string, true>
     */
    protected static function safeHeaderKeys(array $additionalSafeHeaders): array
    {
        $safeHeaderKeys = [];

        foreach (self::SAFE_HEADER_KEYS as $safeHeaderKey) {
            $safeHeaderKeys[$safeHeaderKey] = true;
        }

        foreach ($additionalSafeHeaders as $safeHeader) {
            if (!is_string($safeHeader)) {
                throw new InvalidArgumentException(
                    'additionalSafeHeaders must contain only strings; received non-string value.'
                );
            }

            $normalized = self::normalizeKey($safeHeader);

            if ($normalized === null) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Unsafe or malformed safe header name: %s.',
                        var_export($safeHeader, true)
                    )
                );
            }

            $safeHeaderKeys[$normalized] = true;
        }

        return $safeHeaderKeys;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected static function sanitizeValue($value, int $depth)
    {
        if ($depth >= 16) {
            return '[truncated]';
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = is_array($item) || is_object($item)
                ? self::sanitizeValue($item, $depth + 1)
                : $item;
        }

        return $sanitized;
    }

    /**
     * @param mixed $value
     */
    public static function mask($value): string
    {
        return '[redacted]';
    }

    /**
     * Redacts values of sensitive query parameters (card tokens, wallet ids,
     * e-mails, tokens) while keeping scheme, host, and path intact. Credentials
     * embedded in the URL are dropped.
     */
    public static function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '[redacted]';
        }

        $result = '';

        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }

        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }

        $result .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $query = [];
            parse_str($parts['query'], $query);

            $sanitized = self::sanitizeValue($query, 0);
            $result .= '?' . http_build_query(is_array($sanitized) ? $sanitized : []);
        }

        return $result;
    }

    /**
     * Path component of a URL without query string, fragment, or credentials.
     */
    public static function urlPath(string $url): string
    {
        $parts = parse_url($url);

        return is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '';
    }

    protected static function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);

        return $normalized !== null && in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    protected static function normalizeKey(string $key): ?string
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key));

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }
}
