<?php

namespace Vladchornyi\Mono\Services;

class PubkeyService extends AbstractService
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->sendRequest('GET');
    }
}
