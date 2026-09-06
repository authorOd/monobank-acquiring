<?php

namespace Vladchornyi\Mono\Services;

class MerchantService extends AbstractService
{
    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->sendRequest('GET', '/details');
    }

    /**
     * Submerchant terminals; their `code` is the InvoiceData `code` field.
     *
     * @return array<string, mixed>
     */
    public function getSubmerchants(): array
    {
        return $this->sendRequest('GET', '/submerchant/list');
    }

    /**
     * Split-payment receivers; their `splitReceiverId` is the BasketOrderItem field.
     *
     * @return array<string, mixed>
     */
    public function getSplitReceivers(): array
    {
        return $this->sendRequest('GET', '/split-receiver/list');
    }
}
