<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class MerchantPaymInfoItem
{
	public ?string $reference;
	public ?string $destination;
	public ?string $comment;

	public ?array $customerEmails;

	/** @var DiscountItem[] */
	public ?array $discounts;

	/** @var BasketOrderItem[] */
	public ?array $basketOrder;

	public function __construct(
		?string $reference = null,
		?string $destination = null,
		?string $comment = null,
		?array $customerEmails = null,
		?array $discounts = null,
		?array $basketOrder = null
	) {
		$this->reference      = $reference;
		$this->destination    = $destination;
		$this->comment        = $comment;
		$this->customerEmails = $customerEmails;
		$this->discounts      = $discounts;
		$this->basketOrder    = $basketOrder;

		$this->validate();
	}

	protected function validate(): void
	{
		if ($this->destination && mb_strlen($this->destination) > 280) {
			throw new InvalidArgumentException('destination length must be <= 280 chars');
		}

		if ($this->comment && mb_strlen($this->comment) > 280) {
			throw new InvalidArgumentException('comment length must be <= 280 chars');
		}

		if ($this->discounts) {
			foreach ($this->discounts as $d) {
				if (!$d instanceof DiscountItem) {
					throw new InvalidArgumentException('discounts must contain DiscountItem');
				}
			}
		}

		if ($this->basketOrder) {
			foreach ($this->basketOrder as $b) {
				if (!$b instanceof BasketOrderItem) {
					throw new InvalidArgumentException('basketOrder must contain BasketOrderItem');
				}
			}
		}
	}

	public function toArray(): array
	{
		return [
			'reference'      => $this->reference,
			'destination'    => $this->destination,
			'comment'        => $this->comment,
			'customerEmails' => $this->customerEmails,
			'discounts'      => $this->discounts
				? array_map(fn($d) => $d->toArray(), $this->discounts)
				: null,
			'basketOrder'    => $this->basketOrder
				? array_map(fn($b) => $b->toArray(), $this->basketOrder)
				: null,
		];
	}
}
