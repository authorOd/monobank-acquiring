<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Tests\Support\PublicApiSnapshot;

final class PublicApiSnapshotTest extends TestCase
{
    /**
     * 2.1.0 (additive only, reviewed against 2.0.0 `8865f24e…`):
     * MonoPublicKeyUnavailableException, SubscriptionService::iterateSubscriptionList(),
     * SubscriptionService::iterateSubscriptionPayments(), InputValidator::secureBaseUrl().
     */
    private const V2_API_HASH = 'a1e2174ccf556fd13da241a99c39475345cd9686074544144fe95b381ddc3a43';

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
