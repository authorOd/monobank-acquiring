<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Tests\Support\PublicApiSnapshot;

final class PublicApiSnapshotTest extends TestCase
{
    private const V2_API_HASH = '37f11f7a7acfff963e0bc0927aec4ed5f6097625af8a9d9e4db7267e15703006';

    public function testV2PublicApiMatchesTheReviewedSnapshot(): void
    {
        $surface = PublicApiSnapshot::capture(dirname(__DIR__, 2) . '/src');
        $encoded = json_encode(
            $surface,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $actualHash = hash('sha256', $encoded);

        self::assertSame(
            self::V2_API_HASH,
            $actualHash,
            "The public API changed. Review the surface below and update the v2 snapshot intentionally:\n"
                . $encoded
        );
    }
}
