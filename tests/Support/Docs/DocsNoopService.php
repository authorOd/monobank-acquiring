<?php

namespace Vladchornyi\Mono\Tests\Support\Docs;

/** Accepts any method call and property access; stands in for $orders, $wallets, $logger. */
final class DocsNoopService
{
    public ?string $modifiedDate = null;

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): self
    {
        return $this;
    }
}
