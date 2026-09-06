<?php

namespace Vladchornyi\Mono\Support;

use DateTimeImmutable;

final class Rfc3339
{
    private const PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/D';

    /**
     * Parses an RFC3339 timestamp into a DateTimeImmutable. Fractional seconds
     * accept 0 to 9 digits (Monobank backends emit RFC3339Nano); digits beyond
     * microseconds are truncated because DateTime stores microsecond precision.
     * Use parseInstant() when exact ordering matters.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        return self::parseParts($value)[0] ?? null;
    }

    /**
     * Parses an RFC3339 timestamp with full nanosecond precision.
     */
    public static function parseInstant(string $value): ?Instant
    {
        $parts = self::parseParts($value);

        if ($parts === null) {
            return null;
        }

        [$date, $nanoseconds] = $parts;

        return new Instant($date->getTimestamp(), $nanoseconds);
    }

    /**
     * @return array{0: DateTimeImmutable, 1: int}|null microsecond-precision date and full nanoseconds
     */
    private static function parseParts(string $value): ?array
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $timezone = $matches[3] === 'Z' ? '+00:00' : $matches[3];

        // PHP would silently normalise +24:00 or +00:99; RFC3339 allows hours 00-23 and minutes 00-59.
        if ((int) substr($timezone, 1, 2) > 23 || (int) substr($timezone, 4, 2) > 59) {
            return null;
        }

        $fraction = $matches[2];
        $nanoseconds = $fraction !== '' ? (int) str_pad($fraction, 9, '0') : 0;

        if ($fraction !== '') {
            $normalized = $matches[1] . '.' . substr(str_pad($fraction, 6, '0'), 0, 6) . $timezone;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        } else {
            $normalized = $matches[1] . $timezone;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        }

        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

        if ($date === false || $hasErrors) {
            return null;
        }

        return [$date, $nanoseconds];
    }
}
