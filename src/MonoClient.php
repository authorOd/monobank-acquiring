<?php

namespace Vladchornyi\Mono;

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Http\CurlHttpClient;
use Vladchornyi\Mono\Services\InvoiceService;
use Vladchornyi\Mono\Services\MerchantService;
use Vladchornyi\Mono\Services\PubkeyService;
use Vladchornyi\Mono\Services\StatementService;
use Vladchornyi\Mono\Services\SubscriptionService;
use Vladchornyi\Mono\Services\WebhookService;

class MonoClient
{
    protected string $apiKey;
    protected string $baseUrl;
    protected HttpClientInterface $httpClient;

    /** @var array<string, mixed> */
    protected array $httpOptions;

    /**
     * @param array<string, mixed> $httpOptions
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.monobank.ua/api/merchant',
        ?HttpClientInterface $httpClient = null,
        array $httpOptions = []
    )
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpOptions = $httpOptions;
        $this->httpClient = $httpClient ?? new CurlHttpClient($httpOptions);
    }

    public function invoices(): InvoiceService
    {
        return new InvoiceService($this->apiKey, $this->baseUrl . '/invoice', $this->httpClient, $this->httpOptions);
    }

    public function statements(): StatementService
    {
        return new StatementService($this->apiKey, $this->baseUrl . '/statement', $this->httpClient, $this->httpOptions);
    }

    public function pubkey(): PubkeyService
    {
        return new PubkeyService($this->apiKey, $this->baseUrl . '/pubkey', $this->httpClient, $this->httpOptions);
    }

    public function subscriptions(): SubscriptionService
    {
        return new SubscriptionService($this->apiKey, $this->baseUrl . '/subscription', $this->httpClient, $this->httpOptions);
    }

    public function merchant(): MerchantService
    {
        return new MerchantService($this->apiKey, $this->baseUrl, $this->httpClient, $this->httpOptions);
    }

    public function webhooks(): WebhookService
    {
        return new WebhookService($this->pubkey());
    }
}
