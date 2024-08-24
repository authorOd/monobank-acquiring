<?php

namespace Vladchornyi\Mono;

use Vladchornyi\Mono\Services\InvoiceService;
use Vladchornyi\Mono\Services\StatementService;

class MonoClient
{
    protected $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function invoices()
    {
        return new InvoiceService($this->apiKey);
    }

    public function statements()
    {
        return new StatementService($this->apiKey);
    }
}