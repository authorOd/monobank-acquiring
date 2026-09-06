<?php

namespace Vladchornyi\Mono\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A point in time with nanosecond precision, used to order webhook events
 * exactly. Monobank emits RFC3339Nano timestamps; PHP's DateTime keeps only
 * microseconds, so two events 800 ns apart would compare equal. Keep the raw
 * `modifiedDate` string (or this object) in storage and compare with it.
 */
final class Instant
{
    private int $seconds;
    private int $nanoseconds;

    public function __construct(int $seconds, int $nanoseconds)
    {
        if ($nanoseconds < 0 || $nanoseconds > 999999999) {
            throw new InvalidArgumentException('nanoseconds must be between 0 and 999999999.');
        }

        $this->seconds = $seconds;
        $this->nanoseconds = $nanoseconds;
    }

    public static function parse(string $rfc3339): ?self
    {
        return Rfc3339::parseInstant($rfc3339);
    }

    public static function fromDateTime(DateTimeInterface $dateTime): self
    {
        return new self($dateTime->getTimestamp(), (int) $dateTime->format('u') * 1000);
    }

    /**
     * @param self|DateTimeInterface|string $value RFC3339 string, DateTime (microsecond precision), or Instant
     */
    public static function from($value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return self::fromDateTime($value);
        }

        $instant = self::parse($value);

        if ($instant === null) {
            throw new InvalidArgumentException('Stored timestamp is not a valid RFC3339 value: ' . $value);
        }

        return $instant;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    public function nanoseconds(): int
    {
        return $this->nanoseconds;
    }

    /** Returns -1, 0, or 1 like the spaceship operator. */
    public function compareTo(self $other): int
    {
        return [$this->seconds, $this->nanoseconds] <=> [$other->seconds, $other->nanoseconds];
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    /** Microsecond-precision convenience view (nanoseconds are truncated). */
    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $this->seconds, intdiv($this->nanoseconds, 1000)),
            new DateTimeZone('UTC')
        );

        if ($date === false) {
            throw new InvalidArgumentException('Instant cannot be represented as a DateTimeImmutable.');
        }

        return $date;
    }

    /** Canonical UTC RFC3339 form with nine fractional digits, safe to store and compare as a string. */
    public function toRfc3339(): string
    {
        return gmdate('Y-m-d\TH:i:s', $this->seconds) . sprintf('.%09dZ', $this->nanoseconds);
    }

    public function __toString(): string
    {
        return $this->toRfc3339();
    }
}
