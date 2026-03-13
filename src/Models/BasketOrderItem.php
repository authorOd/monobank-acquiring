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
	public ?array  $tax;
	public ?string $uktzed;

	/** @var DiscountItem[] */
	public ?array $discounts;

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
		?array $discounts = null
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

		$this->validate();
	}

	protected function validate(): void
	{
		if ($this->qty <= 0) {
			throw new InvalidArgumentException('qty must be > 0');
		}

		if ($this->sum <= 0) {
			throw new InvalidArgumentException('sum must be > 0');
		}

		if (empty($this->code)) {
			throw new InvalidArgumentException('code is required for fiscalization');
		}

		if ($this->discounts) {
			foreach ($this->discounts as $d) {
				if (!$d instanceof DiscountItem) {
					throw new InvalidArgumentException('discounts must contain DiscountItem');
				}
			}
		}
	}

	public function toArray(): array
	{
		return [
			'name'      => $this->name,
			'qty'       => $this->qty,
			'sum'       => $this->sum,
			'total'     => intval($this->qty * $this->sum),
			'code'      => $this->code,
			'icon'      => $this->icon,
			'unit'      => $this->unit,
			'barcode'   => $this->barcode,
			'header'    => $this->header,
			'footer'    => $this->footer,
			'tax'       => $this->tax,
			'uktzed'    => $this->uktzed,
			'discounts' => $this->discounts
				? array_map(fn($d) => $d->toArray(), $this->discounts)
				: null,
		];
	}
}
