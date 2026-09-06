<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Support\SensitiveData;

final class SensitiveDataTest extends TestCase
{
    public function testSensitiveAliasesAreFullyRedactedRecursively(): void
    {
        $sanitized = SensitiveData::sanitize([
            'Authorization' => 'Bearer secret',
            'X_Token' => 'secret-token',
            'nested' => [
                'card_token' => 'card-token',
                'cvv' => '123',
                'safe' => 'visible',
            ],
        ]);

        self::assertSame('[redacted]', $sanitized['Authorization']);
        self::assertSame('[redacted]', $sanitized['X_Token']);
        self::assertSame('[redacted]', $sanitized['nested']['card_token']);
        self::assertSame('[redacted]', $sanitized['nested']['cvv']);
        self::assertSame('visible', $sanitized['nested']['safe']);
    }

    public function testTokensCookiesAndClientSecretsAreRedacted(): void
    {
        $sanitized = SensitiveData::sanitize([
            'Cookie' => 'session=secret',
            'Set-Cookie' => 'session=secret',
            'X-Api-Key' => 'api-key',
            'Client_Secret' => 'client-secret',
            'access_token' => 'access-token',
            'refresh-token' => 'refresh-token',
        ]);

        foreach ($sanitized as $value) {
            self::assertSame('[redacted]', $value);
        }
    }

    public function testHeaderSanitizerRedactsUnknownHeadersByDefault(): void
    {
        $sanitized = SensitiveData::sanitizeHeaders([
            'Content-Type' => 'application/json',
            'X-Request-Id' => 'request-1',
            'X-Custom-Internal' => 'do-not-log',
            'Authorization' => 'Bearer secret',
        ]);

        self::assertSame('application/json', $sanitized['Content-Type']);
        self::assertSame('request-1', $sanitized['X-Request-Id']);
        self::assertSame('[redacted]', $sanitized['X-Custom-Internal']);
        self::assertSame('[redacted]', $sanitized['Authorization']);
    }

    public function testHeaderSanitizerAllowsExplicitlySafeAdditionalHeaders(): void
    {
        $sanitized = SensitiveData::sanitizeHeaders(
            [
                'X-Correlation-Id' => 'corr-1',
                'Traceparent' => '00-...',
                'X-Custom' => 'hide-me',
                'Authorization' => 'secret',
            ],
            ['X-Correlation-Id', 'Traceparent']
        );

        self::assertSame('corr-1', $sanitized['X-Correlation-Id']);
        self::assertSame('00-...', $sanitized['Traceparent']);
        self::assertSame('[redacted]', $sanitized['X-Custom']);
        self::assertSame('[redacted]', $sanitized['Authorization']);
    }

    public function testHeaderSanitizerUsesCaseAndSeparatorInsensitiveAllowlistMatching(): void
    {
        $sanitized = SensitiveData::sanitizeHeaders(
            [
                'x_correlation_id' => 'corr-2',
                'TRACE_PARENT' => '00-...',
            ],
            ['X-Correlation Id', 'trace-parent']
        );

        self::assertSame('corr-2', $sanitized['x_correlation_id']);
        self::assertSame('00-...', $sanitized['TRACE_PARENT']);
    }

    public function testSensitiveHeaderCannotBeAllowlistedAndSanitizedRecursively(): void
    {
        $sanitized = SensitiveData::sanitizeHeaders(
            [
                'Authorization' => 'Bearer token',
                'X-Request-Id' => [
                    'authorization' => 'secret',
                    'requestId' => 'request-2',
                    'nested' => ['token' => 'nested-secret'],
                ],
            ],
            ['Authorization']
        );

        self::assertSame('[redacted]', $sanitized['Authorization']);
        self::assertSame('[redacted]', $sanitized['X-Request-Id']['authorization']);
        self::assertSame('request-2', $sanitized['X-Request-Id']['requestId']);
        self::assertSame('[redacted]', $sanitized['X-Request-Id']['nested']['token']);
    }

    public function testHeaderSanitizerRejectsMalformedSafeHeaderEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsafe or malformed safe header name');
        SensitiveData::sanitizeHeaders(['Content-Type' => 'application/json'], ['']);
    }

    public function testHeaderSanitizerRejectsNonStringSafeHeaders(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain only strings');
        SensitiveData::sanitizeHeaders(
            ['Content-Type' => 'application/json'],
            ['X-Request-Id', 123]
        );
    }

    public function testObjectsDepthAndMaskingAreHandledSafely(): void
    {
        $object = new \stdClass();
        $object->accessToken = 'secret';
        $object->safe = 'visible';

        self::assertSame([
            'accessToken' => '[redacted]',
            'safe' => 'visible',
        ], SensitiveData::sanitize($object));

        $nested = 'deepest';
        for ($depth = 0; $depth < 17; ++$depth) {
            $nested = ['next' => $nested];
        }

        $sanitized = SensitiveData::sanitize($nested);
        for ($depth = 0; $depth < 16; ++$depth) {
            $sanitized = $sanitized['next'];
        }

        self::assertSame('[truncated]', $sanitized);
        self::assertSame('[redacted]', SensitiveData::mask('anything'));
    }

    public function testSanitizeUrlRedactsSensitiveQueryValuesOnly(): void
    {
        $url = 'https://user:pass@example.test:8443/api/merchant/wallet/card'
            . '?cardToken=abc&walletId=w1&email=a%40b.io&invoiceId=p2_1#frag';

        self::assertSame(
            'https://example.test:8443/api/merchant/wallet/card'
                . '?cardToken=%5Bredacted%5D&walletId=%5Bredacted%5D&email=%5Bredacted%5D&invoiceId=p2_1',
            SensitiveData::sanitizeUrl($url)
        );
        self::assertSame('/api/merchant/wallet/card', SensitiveData::urlPath($url));
        self::assertSame('https://example.test/path', SensitiveData::sanitizeUrl('https://example.test/path'));
    }
}
