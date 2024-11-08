<?php

namespace Vladchornyi\Mono;

use Vladchornyi\Mono\Services\InvoiceService;
use Vladchornyi\Mono\Services\PubkeyService;
use Vladchornyi\Mono\Services\StatementService;

class MonoClient
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.monobank.ua/api/merchant')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function invoices(): InvoiceService
    {
        return new InvoiceService($this->apiKey, $this->baseUrl . '/invoice');
    }

    public function statements(): StatementService
    {
        return new StatementService($this->apiKey, $this->baseUrl . '/statement');
    }

    public function pubkey(): PubkeyService
    {
        return new PubkeyService($this->apiKey, $this->baseUrl . '/pubkey');
    }
}