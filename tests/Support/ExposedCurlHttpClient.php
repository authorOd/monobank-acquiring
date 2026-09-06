<?php

namespace Vladchornyi\Mono\Tests\Support;

use Vladchornyi\Mono\Http\CurlHttpClient;

final class ExposedCurlHttpClient extends CurlHttpClient
{
    public function exposeRetryDelay(?string $retryAfter): ?int
    {
        return $this->retryDelayFromHeader($retryAfter);
    }

    public function exposeWait(int $attempt, ?string $retryAfter): void
    {
        $this->waitBeforeRetry($attempt, $retryAfter);
    }

    /**
     * @param array<array-key, mixed>|null $body
     */
    public function exposeEncodeBody(?array $body): ?string
    {
        return $this->encodeBody($body);
    }
}
