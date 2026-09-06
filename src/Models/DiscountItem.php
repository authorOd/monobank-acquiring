<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class DiscountItem
{
    public const TYPE_DISCOUNT = 'DISCOUNT';
    public const TYPE_EXTRA_CHARGE = 'EXTRA_CHARGE';
    public const MODE_PERCENT = 'PERCENT';
    public const MODE_VALUE = 'VALUE';

    public string $type;   // DISCOUNT | EXTRA_CHARGE
    public string $mode;   // PERCENT | VALUE
    public float $value;   // decimal >= 0.01, in steps of 0.01
    /**
     * Tax rates for fiscalization. The Monobank schema declares this as an
     * untyped array (the docs renderer shows "objects"); its description is the
     * same as basketOrder[].tax, which is a list of integer tax codes. Both
     * integer codes and associative-array objects are accepted and passed
     * through unchanged.
     *
     * @var array<int, int|array<string, mixed>>|null
     */
    public ?array $tax;

    /**
     * @param array<int, int|array<string, mixed>>|null $tax integer tax codes or tax objects
     */
    public function __construct(
        string $type,
        string $mode,
        float $value,
        ?array $tax = null
    ) {
        $this->type  = $type;
        $this->mode  = $mode;
        $this->value = $value;
        $this->tax   = $tax;

        $this->validate();
    }

    protected function validate(): void
    {
        if (!in_array($this->type, [self::TYPE_DISCOUNT, self::TYPE_EXTRA_CHARGE], true)) {
            throw new InvalidArgumentException('Discount type must be DISCOUNT or EXTRA_CHARGE');
        }

        if (!in_array($this->mode, [self::MODE_PERCENT, self::MODE_VALUE], true)) {
            throw new InvalidArgumentException('Discount mode must be PERCENT or VALUE');
        }

        if (!is_finite($this->value) || $this->value < 0.01) {
            throw new InvalidArgumentException('Discount value must be >= 0.01');
        }

        $rounded = round($this->value, 2);
        $tolerance = 2 * PHP_FLOAT_EPSILON * max(1.0, abs($this->value));
        if (abs($this->value - $rounded) > $tolerance) {
            throw new InvalidArgumentException('Discount value must be a multiple of 0.01');
        }
        // Remove binary float noise (e.g. 0.1 + 0.2) from the actual JSON value.
        $this->value = $rounded;

        if ($this->mode === self::MODE_PERCENT && $this->value > 100) {
            throw new InvalidArgumentException('Percent discount value must be <= 100');
        }

        if ($this->tax !== null) {
            foreach ($this->tax as $taxEntry) {
                if (is_int($taxEntry) && $taxEntry >= 0) {
                    continue;
                }

                if (is_array($taxEntry) && $taxEntry !== [] && !self::isList($taxEntry)) {
                    continue;
                }

                throw new InvalidArgumentException(
                    'Discount tax must contain non-negative integer tax codes or tax objects (associative arrays)'
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @return array{type: string, mode: string, value: float, tax?: array<int, int|array<string, mixed>>}
     */
    public function toArray(): array
    {
        $this->validate();

        return array_filter([
            'type'  => $this->type,
            'mode'  => $this->mode,
            'value' => $this->value,
            'tax'   => $this->tax !== null ? array_values($this->tax) : null,
        ], static fn($value): bool => $value !== null);
    }
}
