<?php

namespace Vladchornyi\Mono\Tests\Support;

use RuntimeException;
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Http\HttpResponse;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: array<array-key, mixed>|null}> */
    public array $requests = [];

    /** @var HttpResponse[] */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];

        $response = array_shift($this->responses);

        if (!$response instanceof HttpResponse) {
            throw new RuntimeException('Unexpected HTTP request: no fake response remains.');
        }

        return $response;
    }
}
