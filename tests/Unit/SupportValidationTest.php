<?php

namespace Vladchornyi\Mono\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Support\Instant;
use Vladchornyi\Mono\Support\Rfc3339;
use Vladchornyi\Mono\Webhooks\WebhookPayload;

final class SupportValidationTest extends TestCase
{
    public function testScalarValidatorsAcceptBoundaryValues(): void
    {
        InputValidator::nonEmptyString('value', 'value');
        InputValidator::positiveInt(1, 'value');
        InputValidator::nonNegativeInt(0, 'value');
        InputValidator::rfc3339('2026-07-01T10:00:00Z', 'value');

        self::assertTrue(true);
    }

    /**
     * @dataProvider invalidScalarProvider
     * @param callable(): void $validation
     */
    public function testScalarValidatorsRejectInvalidValues(callable $validation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $validation();
    }

    /** @return array<string, array{callable(): void}> */
    public function invalidScalarProvider(): array
    {
        return [
            'empty string' => [static function (): void {
                InputValidator::nonEmptyString(' ', 'value');
            }],
            'non-positive integer' => [static function (): void {
                InputValidator::positiveInt(0, 'value');
            }],
            'negative integer' => [static function (): void {
                InputValidator::nonNegativeInt(-1, 'value');
            }],
            'invalid timestamp' => [static function (): void {
                InputValidator::rfc3339('tomorrow', 'value');
            }],
        ];
    }

    /**
     * @dataProvider secureBaseUrlProvider
     */
    public function testBaseUrlRequiresHttpsExceptForLoopbackHosts(string $baseUrl, bool $accepted): void
    {
        try {
            InputValidator::secureBaseUrl($baseUrl, 'baseUrl');
            $result = true;
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('baseUrl', $exception->getMessage());
            $result = false;
        }

        self::assertSame($accepted, $result, $baseUrl);
    }

    /** @return array<string, array{string, bool}> */
    public function secureBaseUrlProvider(): array
    {
        return [
            'production https' => ['https://api.monobank.ua/api/merchant', true],
            'uppercase https scheme' => ['HTTPS://api.monobank.ua/api/merchant', true],
            'loopback ipv4' => ['http://127.0.0.1:8080/api/merchant', true],
            'loopback ipv4 range' => ['http://127.10.20.30', true],
            'localhost' => ['http://localhost:8080', true],
            'uppercase localhost' => ['http://LOCALHOST', true],
            'loopback ipv6' => ['http://[::1]:8080', true],
            'plain http remote' => ['http://api.monobank.ua/api/merchant', false],
            'plain http private network' => ['http://10.0.0.5/api/merchant', false],
            'localhost suffix' => ['http://localhost.attacker.example', false],
            'loopback-looking hostname' => ['http://127.0.0.1.attacker.example', false],
            'unspecified ipv4' => ['http://0.0.0.0', false],
            'non-http scheme' => ['ftp://api.monobank.ua', false],
        ];
    }

    public function testClientAndServicesRejectAPlainHttpBaseUrl(): void
    {
        foreach (
            [
                static fn () => new \Vladchornyi\Mono\MonoClient('token', 'http://api.monobank.ua/api/merchant'),
                static fn () => new \Vladchornyi\Mono\Services\MerchantService(
                    'token',
                    'http://api.monobank.ua/api/merchant',
                    new \Vladchornyi\Mono\Tests\Support\FakeHttpClient()
                ),
            ] as $construct
        ) {
            try {
                $construct();
                self::fail('A plain HTTP base URL was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('must use HTTPS', $exception->getMessage());
            }
        }
    }

    public function testRfc3339PreservesSupportedFractionPrecision(): void
    {
        $date = Rfc3339::parse('2026-07-01T10:00:00.1234+03:00');

        self::assertNotNull($date);
        self::assertSame('123400', $date->format('u'));
    }

    public function testRfc3339AcceptsNanosecondFractionsAndTruncatesToMicroseconds(): void
    {
        $date = Rfc3339::parse('2026-07-01T10:00:00.123456789Z');

        self::assertNotNull($date);
        self::assertSame('123456', $date->format('u'));
        self::assertNull(Rfc3339::parse('2026-07-01T10:00:00.1234567890Z'));
        self::assertNull(Rfc3339::parse('2026-07-01T10:00:00.Z'));
    }

    public function testInstantKeepsNanosecondsAndNormalizesZones(): void
    {
        $instant = Instant::parse('2026-07-01T13:00:00.123456789+03:00');

        self::assertNotNull($instant);
        self::assertSame(123456789, $instant->nanoseconds());
        self::assertSame('2026-07-01T10:00:00.123456789Z', $instant->toRfc3339());
        self::assertSame('2026-07-01T10:00:00.123456', $instant->toDateTimeImmutable()->format('Y-m-d\TH:i:s.u'));
        self::assertTrue(Instant::from('2026-07-01T10:00:00.123456790Z')->isAfter($instant));
        self::assertTrue(Instant::from(new \DateTimeImmutable('2026-07-01T10:00:00.123456Z'))->equals(
            Instant::from('2026-07-01T10:00:00.123456000Z')
        ));
        self::assertSame(0, Instant::from($instant)->compareTo($instant));
        self::assertNull(Instant::parse('2026-07-01 10:00:00'));

        $this->expectException(InvalidArgumentException::class);
        new Instant(0, 1000000000);
    }

    /**
     * @dataProvider timezoneOffsetProvider
     */
    public function testEveryParsingPathAgreesOnTimezoneOffsetBounds(string $value, bool $valid): void
    {
        $date = Rfc3339::parse($value);
        $instant = Rfc3339::parseInstant($value);

        self::assertSame($valid, $date !== null, "Rfc3339::parse {$value}");
        self::assertSame($valid, $instant !== null, "Rfc3339::parseInstant {$value}");
        self::assertSame($valid, Instant::parse($value) !== null, "Instant::parse {$value}");

        try {
            InputValidator::rfc3339($value, 'dateFrom');
            self::assertTrue($valid, "InputValidator accepted {$value}");
        } catch (InvalidArgumentException $exception) {
            self::assertFalse($valid, "InputValidator rejected {$value}");
        }

        $payload = ['invoiceId' => 'p2_1', 'modifiedDate' => $value];

        try {
            $modified = WebhookPayload::fromArray($payload)->modifiedDate();
            $instantFromPayload = WebhookPayload::fromArray($payload)->modifiedInstant();
            $service = WebhookService::create()->getModifiedInstant($payload);
            self::assertTrue($valid, "WebhookPayload accepted {$value}");
            self::assertNotNull($modified);
            self::assertNotNull($instantFromPayload);
            self::assertNotNull($service);
            self::assertSame($date?->getTimestamp(), $instantFromPayload->seconds(), 'same instant on every path');
        } catch (MonoWebhookException $exception) {
            self::assertFalse($valid, "WebhookPayload rejected {$value}");
        }
    }

    /** @return array<string, array{string, bool}> */
    public function timezoneOffsetProvider(): array
    {
        return [
            'utc Z' => ['2026-07-01T10:00:00Z', true],
            'zero offset' => ['2026-07-01T10:00:00+00:00', true],
            'negative zero' => ['2026-07-01T10:00:00-00:00', true],
            'max positive' => ['2026-07-01T10:00:00+23:59', true],
            'max negative' => ['2026-07-01T10:00:00-23:59', true],
            'kyiv summer' => ['2026-07-01T13:00:00.5+03:00', true],
            'hour 24' => ['2026-07-01T10:00:00+24:00', false],
            'minute 60' => ['2026-07-01T10:00:00+00:60', false],
            'minute 99' => ['2026-07-01T10:00:00+00:99', false],
            'negative hour 24' => ['2026-07-01T10:00:00-24:00', false],
            'offset without colon' => ['2026-07-01T10:00:00+0300', false],
            'lowercase z' => ['2026-07-01T10:00:00z', false],
            'no offset' => ['2026-07-01T10:00:00', false],
            'hour 24 in time' => ['2026-07-01T24:00:00Z', false],
            'minute 60 in time' => ['2026-07-01T10:60:00Z', false],
            'leap second' => ['2026-07-01T10:00:60Z', false],
            'invalid day' => ['2026-02-30T10:00:00Z', false],
        ];
    }
}
