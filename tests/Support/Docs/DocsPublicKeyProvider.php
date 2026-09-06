<?php

namespace Vladchornyi\Mono\Tests\Support\Docs;

use Vladchornyi\Mono\Contracts\PublicKeyProviderInterface;

final class DocsPublicKeyProvider implements PublicKeyProviderInterface
{
    private string $publicKey;

    public function __construct(string $publicKey)
    {
        $this->publicKey = $publicKey;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function refreshPublicKey(): string
    {
        return $this->publicKey;
    }
}
