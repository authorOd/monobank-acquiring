<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class MerchantPaymInfoItem
{
    public ?string $reference;
    public ?string $destination;
    public ?string $comment;

    /** @var string[]|null */
    public ?array $customerEmails;

    /** @var DiscountItem[] */
    public ?array $discounts;

    /** @var BasketOrderItem[] */
    public ?array $basketOrder;

    /** @var array<string, string>|null */
    public ?array $metadata;

    /**
     * @param string[]|null $customerEmails
     * @param DiscountItem[]|null $discounts
     * @param BasketOrderItem[]|null $basketOrder
     * @param array<string, string>|null $metadata
     */
    public function __construct(
        ?string $reference = null,
        ?string $destination = null,
        ?string $comment = null,
        #[\SensitiveParameter] ?array $customerEmails = null,
        ?array $discounts = null,
        ?array $basketOrder = null,
        ?array $metadata = null
    ) {
        $this->reference      = $reference;
        $this->destination    = $destination;
        $this->comment        = $comment;
        $this->customerEmails = $customerEmails;
        $this->discounts      = $discounts;
        $this->basketOrder    = $basketOrder;
        $this->metadata       = $metadata;

        $this->validate();
    }

    protected function validate(): void
    {
        if ($this->reference !== null && mb_strlen($this->reference) > 255) {
            throw new InvalidArgumentException('reference length must be <= 255 chars');
        }

        if ($this->destination !== null && mb_strlen($this->destination) > 280) {
            throw new InvalidArgumentException('destination length must be <= 280 chars');
        }

        if ($this->comment !== null && mb_strlen($this->comment) > 280) {
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

        if ($this->metadata !== null) {
            if (count($this->metadata) > 10) {
                throw new InvalidArgumentException('metadata must contain at most 10 entries');
            }

            foreach ($this->metadata as $key => $value) {
                if (!is_string($key) || trim($key) === '' || !is_string($value)) {
                    throw new InvalidArgumentException('metadata must be a non-empty string-to-string map');
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->validate();

        // array_values() guarantees JSON lists even after unset()/filtering left gaps in
        // the keys; json_encode() would otherwise emit {"1": ...} objects the API rejects.
        // An empty metadata map is omitted: [] would encode as a JSON list, not an object.
        return array_filter([
            'reference'      => $this->reference,
            'destination'    => $this->destination,
            'comment'        => $this->comment,
            'customerEmails' => $this->customerEmails !== null
                ? array_values(array_unique($this->customerEmails))
                : null,
            'discounts'      => $this->discounts !== null
                ? array_values(array_map(fn($d) => $d->toArray(), $this->discounts))
                : null,
            'basketOrder'    => $this->basketOrder !== null
                ? array_values(array_map(fn($b) => $b->toArray(), $this->basketOrder))
                : null,
            'metadata'       => $this->metadata !== null && $this->metadata !== [] ? $this->metadata : null,
        ], fn($value) => $value !== null);
    }
}
