<?php

namespace Vladchornyi\Mono\Support;

class SensitiveData
{
    /** @var string[] */
    protected const SENSITIVE_KEYS = [
        'x-token',
        'x-sign',
        'token',
        'api_key',
        'apiKey',
        'cardToken',
        'walletId',
        'tranId',
        'rrn',
        'approvalCode',
        'maskedPan',
        'email',
        'customerEmails',
    ];

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function sanitize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = self::mask($item);
                continue;
            }

            $sanitized[$key] = is_array($item) ? self::sanitize($item) : $item;
        }

        return $sanitized;
    }

    /**
     * @param mixed $value
     */
    public static function mask($value): string
    {
        if (is_array($value)) {
            return '[redacted]';
        }

        $value = (string) $value;
        $length = strlen($value);

        if ($length <= 8) {
            return '[redacted]';
        }

        return substr($value, 0, 4) . str_repeat('*', max(4, $length - 8)) . substr($value, -4);
    }

    protected static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (strcasecmp($key, $sensitiveKey) === 0) {
                return true;
            }
        }

        return false;
    }
}
