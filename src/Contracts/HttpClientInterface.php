<?php

namespace Vladchornyi\Mono\Contracts;

use Vladchornyi\Mono\Http\HttpResponse;

interface HttpClientInterface
{
    /**
     * The URL query, headers (`X-Token`), and body carry credentials and card
     * data. PHP does not inherit parameter attributes, so implementations should
     * repeat `#[\SensitiveParameter]` to keep them out of exception traces.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    public function send(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] ?array $body = null
    ): HttpResponse;
}
