<?php

namespace Vladchornyi\Mono\Contracts;

use Vladchornyi\Mono\Http\HttpResponse;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse;
}
