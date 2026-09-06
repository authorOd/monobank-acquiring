<?php

namespace Vladchornyi\Mono;

use InvalidArgumentException;
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Contracts\PublicKeyCacheInterface;
use Vladchornyi\Mono\Contracts\PublicKeyProviderInterface;
use Vladchornyi\Mono\Http\CurlHttpClient;
use Vladchornyi\Mono\Services\InvoiceService;
use Vladchornyi\Mono\Services\MerchantService;
use Vladchornyi\Mono\Services\PubkeyService;
use Vladchornyi\Mono\Services\StatementService;
use Vladchornyi\Mono\Services\SubscriptionService;
use Vladchornyi\Mono\Services\WalletService;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;
use Vladchornyi\Mono\Webhooks\CachedPublicKeyProvider;

class MonoClient
{
    /** httpOptions keys that become the optional `X-Cms` / `X-Cms-Version` headers. */
    public const OPTION_CMS_NAME = 'cms_name';
    public const OPTION_CMS_VERSION = 'cms_version';

    protected string $apiKey;
    protected string $baseUrl;
    protected HttpClientInterface $httpClient;
    protected ?PublicKeyProviderInterface $publicKeyProvider;
    protected ?PublicKeyCacheInterface $publicKeyCache;

    /** @var array<string, mixed> */
    protected array $httpOptions;

    /** @var array<string, string> */
    protected array $defaultHeaders = [];

    protected ?InvoiceService $invoiceService = null;
    protected ?StatementService $statementService = null;
    protected ?PubkeyService $pubkeyService = null;
    protected ?SubscriptionService $subscriptionService = null;
    protected ?MerchantService $merchantService = null;
    protected ?WalletService $walletService = null;
    protected ?WebhookService $webhookService = null;

    private static bool $processLocalCacheWarned = false;

    /**
     * @param array<string, mixed> $httpOptions Transport options for CurlHttpClient plus the optional
     *                                          `cms_name` / `cms_version` keys (sent as X-Cms headers).
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.monobank.ua/api/merchant',
        ?HttpClientInterface $httpClient = null,
        array $httpOptions = [],
        ?PublicKeyProviderInterface $publicKeyProvider = null,
        ?PublicKeyCacheInterface $publicKeyCache = null
    ) {
        InputValidator::nonEmptyString($apiKey, 'apiKey');
        InputValidator::httpUrl($baseUrl, 'baseUrl');

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpOptions = $httpOptions;
        $this->defaultHeaders = $this->cmsHeaders($httpOptions);
        $this->httpClient = $httpClient ?? new CurlHttpClient($httpOptions);
        $this->publicKeyProvider = $publicKeyProvider;
        $this->publicKeyCache = $publicKeyCache;
    }

    public function invoices(): InvoiceService
    {
        return $this->invoiceService ??= new InvoiceService(
            $this->apiKey,
            $this->baseUrl . '/invoice',
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    public function statements(): StatementService
    {
        return $this->statementService ??= new StatementService(
            $this->apiKey,
            $this->baseUrl . '/statement',
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    public function pubkey(): PubkeyService
    {
        return $this->pubkeyService ??= new PubkeyService(
            $this->apiKey,
            $this->baseUrl . '/pubkey',
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    public function subscriptions(): SubscriptionService
    {
        return $this->subscriptionService ??= new SubscriptionService(
            $this->apiKey,
            $this->baseUrl . '/subscription',
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    public function merchant(): MerchantService
    {
        return $this->merchantService ??= new MerchantService(
            $this->apiKey,
            $this->baseUrl,
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    public function wallet(): WalletService
    {
        return $this->walletService ??= new WalletService(
            $this->apiKey,
            $this->baseUrl . '/wallet',
            $this->httpClient,
            $this->httpOptions,
            $this->defaultHeaders
        );
    }

    /**
     * Without an explicit `publicKeyProvider` or shared `publicKeyCache` the
     * webhook service falls back to a cache shared by all clients in this
     * process (isolated per base URL and merchant token) so 1.x integrations
     * keep verifying webhooks after the upgrade. The fallback cannot coordinate
     * `/pubkey` refreshes across PHP-FPM workers and is deprecated for
     * production; a deprecation notice is emitted once per process.
     */
    public function webhooks(): WebhookService
    {
        if ($this->webhookService !== null) {
            return $this->webhookService;
        }

        $provider = $this->publicKeyProvider;

        if ($provider === null) {
            $cache = $this->publicKeyCache;

            if ($cache === null) {
                $cache = ArrayPublicKeyCache::processShared();
                $this->warnAboutProcessLocalCache();
            }

            $provider = new CachedPublicKeyProvider($this->pubkey(), cache: $cache);
        }

        return $this->webhookService = WebhookService::create($provider);
    }

    /**
     * @param array<string, mixed> $httpOptions
     * @return array<string, string>
     */
    protected function cmsHeaders(array $httpOptions): array
    {
        $headers = [];

        foreach (
            [
                self::OPTION_CMS_NAME => 'X-Cms',
                self::OPTION_CMS_VERSION => 'X-Cms-Version',
            ] as $option => $header
        ) {
            $value = $httpOptions[$option] ?? null;

            if ($value === null) {
                continue;
            }

            if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("httpOptions.{$option} must be a non-empty string.");
            }

            $headers[$header] = $value;
        }

        return $headers;
    }

    protected function warnAboutProcessLocalCache(): void
    {
        if (self::$processLocalCacheWarned) {
            return;
        }

        self::$processLocalCacheWarned = true;

        @trigger_error(
            'MonoClient::webhooks() is using a process-local public-key cache because neither '
            . 'publicKeyCache nor publicKeyProvider was configured. Inject a shared '
            . 'PublicKeyCacheInterface for multi-worker deployments; the implicit fallback is '
            . 'deprecated and will be removed in 3.0.',
            E_USER_DEPRECATED
        );
    }
}
