<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Http\CurlHttpClient;

abstract class AbstractService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected HttpClientInterface $httpClient;

    /**
     * @param string $apiKey
     * @param array<string, mixed> $httpOptions
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        ?HttpClientInterface $httpClient = null,
        array $httpOptions = []
    )
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = $httpClient ?? new CurlHttpClient($httpOptions);
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    protected function sendRequest(string $method, string $endpoint = '', ?array $data = null, array $query = []): array
    {
        $url = $this->buildUrl($endpoint, $query);
        $method = strtoupper($method);
        $payload = $data === null
            ? null
            : array_filter($data, static fn($value) => $value !== null);

        $response = $this->httpClient->send($method, $url, [
            'X-Token' => $this->apiKey,
            'Content-Type: application/json',
        ], $payload);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new MonoApiException(
                $response->getStatusCode(),
                $method,
                $url,
                $response->getBody(),
                $this->decodeJsonSafely($response->getBody())
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
     * @return array<string, mixed>|null
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
