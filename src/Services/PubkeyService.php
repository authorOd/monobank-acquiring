<?php

namespace Vladchornyi\Mono\Services;

class PubkeyService extends AbstractService
{
    /**
     * @param int $from
     * @param int $to
     * @return mixed
     */
    public function get()
    {
        return $this->sendRequest('GET', '');
    }
}