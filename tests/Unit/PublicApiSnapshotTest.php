<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Tests\Support\PublicApiSnapshot;

final class PublicApiSnapshotTest extends TestCase
{
    private const V2_API_HASH = '8865f24ed49588f10d8b0791a1fab091dd463fcfaa4dd1d40a10beeb68b9042f';

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
