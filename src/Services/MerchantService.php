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
}
