<?php

namespace Vladchornyi\Mono\Contracts;

interface PublicKeyProviderInterface
{
    public function getPublicKey(): string;

    public function refreshPublicKey(): string;
}
