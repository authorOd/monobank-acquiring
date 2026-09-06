<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class BasketOrderItem
{
    public string $name;
    public float $qty;
    public int $sum;
    public string $code;

    public ?string $icon;
    public ?string $unit;
    public ?string $barcode;
    public ?string $header;
    public ?string $footer;
    /** @var int[]|null */
    public ?array $tax;
    public ?string $uktzed;

    /** @var DiscountItem[] */
    public ?array $discounts;
    public ?string $splitReceiverId;

    /**
     * @param int[]|null $tax
     * @param DiscountItem[]|null $discounts
     */
    public function __construct(
        string $name,
        float $qty,
        int $sum,
        string $code,
        ?string $icon = null,
        ?string $unit = null,
        ?string $barcode = null,
        ?string $header = null,
        ?string $footer = null,
        ?array $tax = null,
        ?string $uktzed = null,
        ?array $discounts = null,
        ?string $splitReceiverId = null
    ) {
        $this->name      = $name;
        $this->qty       = $qty;
        $this->sum       = $sum;
        $this->code      = $code;

        $this->icon      = $icon;
        $this->unit      = $unit;
        $this->barcode   = $barcode;
        $this->header    = $header;
        $this->footer    = $footer;
        $this->tax       = $tax;
        $this->uktzed    = $uktzed;
        $this->discounts = $discounts;
        $this->splitReceiverId = $splitReceiverId;

        $this->validate();
    }

    protected function validate(): void
    {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('name is required');
        }

        if (!is_finite($this->qty) || $this->qty <= 0) {
            throw new InvalidArgumentException('qty must be > 0');
        }

        if ($this->sum <= 0) {
            throw new InvalidArgumentException('sum must be > 0');
        }

        if (trim($this->code) === '') {
            throw new InvalidArgumentException('code is required for fiscalization');
        }

        $total = $this->qty * $this->sum;
        if (!is_finite($total) || $total > PHP_INT_MAX || round($total) < 1) {
            throw new InvalidArgumentException('qty multiplied by sum must produce a positive integer total');
        }

        if ($this->tax !== null) {
            foreach ($this->tax as $taxCode) {
                if (!is_int($taxCode)) {
                    throw new InvalidArgumentException('tax must contain integer tax codes');
                }
            }
        }

        if ($this->discounts !== null) {
            foreach ($this->discounts as $d) {
                if (!$d instanceof DiscountItem) {
                    throw new InvalidArgumentException('discounts must contain DiscountItem');
                }
            }
        }

        if ($this->splitReceiverId !== null && trim($this->splitReceiverId) === '') {
            throw new InvalidArgumentException('splitReceiverId must be a non-empty string when provided');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->validate();

        return array_filter([
            'name'      => $this->name,
            'qty'       => $this->qty,
            'sum'       => $this->sum,
            'total'     => (int) round($this->qty * $this->sum),
            'code'      => $this->code,
            'icon'      => $this->icon,
            'unit'      => $this->unit,
            'barcode'   => $this->barcode,
            'header'    => $this->header,
            'footer'    => $this->footer,
            'tax'       => $this->tax !== null ? array_values($this->tax) : null,
            'uktzed'    => $this->uktzed,
            'discounts' => $this->discounts !== null
                ? array_values(array_map(fn($d) => $d->toArray(), $this->discounts))
                : null,
            'splitReceiverId' => $this->splitReceiverId,
        ], fn($value) => $value !== null);
    }
}
