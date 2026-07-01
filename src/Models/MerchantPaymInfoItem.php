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
		if ($this->reference && mb_strlen($this->reference) > 255) {
			throw new InvalidArgumentException('reference length must be <= 255 chars');
		}

		if ($this->destination && mb_strlen($this->destination) > 280) {
			throw new InvalidArgumentException('destination length must be <= 280 chars');
		}

		if ($this->comment && mb_strlen($this->comment) > 280) {
			throw new InvalidArgumentException('comment length must be <= 280 chars');
		}

		if ($this->customerEmails !== null) {
			foreach ($this->customerEmails as $email) {
				if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					throw new InvalidArgumentException('customerEmails must contain valid emails');
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

		if ($this->basketOrder !== null) {
			foreach ($this->basketOrder as $b) {
				if (!$b instanceof BasketOrderItem) {
					throw new InvalidArgumentException('basketOrder must contain BasketOrderItem');
				}
			}
		}
	}

	public function toArray(): array
	{
		return array_filter([
			'reference'      => $this->reference,
			'destination'    => $this->destination,
			'comment'        => $this->comment,
			'customerEmails' => $this->customerEmails,
			'discounts'      => $this->discounts !== null
				? array_map(fn($d) => $d->toArray(), $this->discounts)
				: null,
			'basketOrder'    => $this->basketOrder !== null
				? array_map(fn($b) => $b->toArray(), $this->basketOrder)
				: null,
		], fn($value) => $value !== null);
	}
}
