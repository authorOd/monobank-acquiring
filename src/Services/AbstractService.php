<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Http\CurlHttpClient;
use Vladchornyi\Mono\Support\InputValidator;

abstract class AbstractService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected HttpClientInterface $httpClient;

    /** @var array<string, string> */
    protected array $defaultHeaders;

    /**
     * @param string $apiKey
     * @param array<string, mixed> $httpOptions
     * @param array<string, string> $defaultHeaders Extra headers sent with every request (e.g. X-Cms).
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        ?HttpClientInterface $httpClient = null,
        array $httpOptions = [],
        array $defaultHeaders = []
    ) {
        InputValidator::nonEmptyString($apiKey, 'apiKey');
        InputValidator::httpUrl($baseUrl, 'baseUrl');

        foreach ($defaultHeaders as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Default headers must be a non-empty string-to-string map.');
            }
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new CurlHttpClient($httpOptions);
        $this->defaultHeaders = $defaultHeaders;
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     * @return array<array-key, mixed>
     */
    protected function sendRequest(string $method, string $endpoint = '', ?array $data = null, array $query = []): array
    {
        $url = $this->buildUrl($endpoint, $query);
        $method = strtoupper($method);
        $payload = $data === null
            ? null
            : array_filter($data, static fn($value) => $value !== null);

        $headers = $this->defaultHeaders + [
            'X-Token' => $this->apiKey,
            'Accept' => 'application/json',
        ];
        $headers['X-Token'] = $this->apiKey;

        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->httpClient->send($method, $url, $headers, $payload);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new MonoApiException(
                $response->getStatusCode(),
                $method,
                $url,
                $response->getBody(),
                $this->decodeJsonSafely($response->getBody()),
                $response->getHeaders()
            );
        }

        $body = trim($response->getBody());
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MonoJsonException('Failed to decode JSON response: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new MonoJsonException('Monobank response must be a JSON object or array');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function buildUrl(string $endpoint = '', array $query = []): string
    {
        $url = $this->baseUrl . $endpoint;
        $query = array_filter($query, static fn($value) => $value !== null);

        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected function decodeJsonSafely(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
