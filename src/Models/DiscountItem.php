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
	public float $value;   // decimal >= 0.01

	public function __construct(
		string $type,
		string $mode,
		float $value
	) {
		$this->type  = $type;
		$this->mode  = $mode;
		$this->value = $value;

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

		if ($this->value < 0.01) {
			throw new InvalidArgumentException('Discount value must be >= 0.01');
		}

		if ($this->mode === self::MODE_PERCENT && $this->value > 100) {
			throw new InvalidArgumentException('Percent discount value must be <= 100');
		}
	}

	public function toArray(): array
	{
		return [
			'type'  => $this->type,
			'mode'  => $this->mode,
			'value' => $this->value,
		];
	}
}
