<?php

namespace Vladchornyi\Mono\Services;

class StatementService extends AbstractService
{
    protected $baseUrl = 'https://api.monobank.ua/api/merchant/statement';

    /**
     * @param int $from
     * @param int $to
     * @return mixed
     */
    public function getStatement(int $from, int $to)
    {
        return $this->sendRequest('GET', "?&from=$from&to=$to");
    }
}