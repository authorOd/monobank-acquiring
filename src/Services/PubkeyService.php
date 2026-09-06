<?php

namespace Vladchornyi\Mono\Services;

class PubkeyService extends AbstractService
{
    public function cacheKeyFingerprint(): string
    {
        return hash('sha256', $this->baseUrl . "\0" . $this->apiKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->sendRequest('GET');
    }
}
