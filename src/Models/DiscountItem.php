<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class DiscountItem
{
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
		if (!in_array($this->type, ['DISCOUNT', 'EXTRA_CHARGE'])) {
			throw new InvalidArgumentException('Discount type must be DISCOUNT or EXTRA_CHARGE');
		}

		if (!in_array($this->mode, ['PERCENT', 'VALUE'])) {
			throw new InvalidArgumentException('Discount mode must be PERCENT or VALUE');
		}

		if ($this->value < 0.01) {
			throw new InvalidArgumentException('Discount value must be >= 0.01');
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
