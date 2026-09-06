<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Tests\Support\FakeHttpClient;

final class HttpPipelineTest extends TestCase
{
    public function testPostUsesAValidStringHeaderMap(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"invoiceId":"p2_1","pageUrl":"https://pay.test"}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $client->invoices()->createInvoice(new InvoiceData(100));

        self::assertSame([
            'X-Token' => 'token',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $http->requests[0]['headers']);
    }

    public function testGetEncodesQueryAndDoesNotSendContentType(): void
    {
        $http = new FakeHttpClient(new HttpResponse(200, '{"status":"success"}'));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        $client->invoices()->getInvoiceStatus('p2 hello');

        self::assertSame(
            'https://example.test/api/merchant/invoice/status?invoiceId=p2+hello',
            $http->requests[0]['url']
        );
        self::assertArrayNotHasKey('Content-Type', $http->requests[0]['headers']);
    }

    public function testApiExceptionExposesResponseHeaders(): void
    {
        $http = new FakeHttpClient(new HttpResponse(
            429,
            '{"errCode":"TOO_MANY_REQUESTS"}',
            ['Retry-After' => '3', 'X-Request-Id' => 'request-1']
        ));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        try {
            $client->merchant()->getDetails();
            self::fail('MonoApiException was not thrown.');
        } catch (MonoApiException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertSame('3', $exception->getResponseHeaderLine('retry-after'));
            self::assertSame('request-1', $exception->getResponseHeaders()['X-Request-Id']);
        }
    }

    /**
     * @dataProvider sensitiveQueryRequestProvider
     * @param callable(MonoClient): mixed $request
     * @param string[] $markers
     */
    public function testApiExceptionNeverExposesSensitiveQueryValues(callable $request, array $markers): void
    {
        $http = new FakeHttpClient(new HttpResponse(
            400,
            '{"errCode":"BAD_REQUEST","errText":"invalid"}',
            ['X-Request-Id' => 'request-9']
        ));
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        try {
            $request($client);
            self::fail('MonoApiException was not thrown.');
        } catch (MonoApiException $exception) {
            $context = $exception->getSafeContext();
            $exported = (string) json_encode($context);

            foreach ($markers as $marker) {
                self::assertStringNotContainsString($marker, $exception->getMessage());
                self::assertStringNotContainsString($marker, (string) $exception);
                self::assertStringNotContainsString($marker, $exported);
                self::assertStringNotContainsString($marker, $exception->getSafeUrl());
            }

            // The raw URL is still available for callers that deliberately need it.
            self::assertStringContainsString($markers[0], $exception->getUrl());

            self::assertStringContainsString('%5Bredacted%5D', $exception->getSafeUrl());
            self::assertSame(400, $context['status']);
            self::assertSame($exception->getPath(), $context['path']);
            self::assertStringStartsWith('/api/merchant/', $context['path']);
            self::assertSame('request-9', $context['request_id']);
            self::assertSame('BAD_REQUEST', $context['err_code']);
            self::assertNull($context['retry_after']);
            self::assertSame(
                sprintf('Monobank API error 400 for %s %s', $context['method'], $context['path']),
                $exception->getMessage()
            );
        }
    }

    /** @return array<string, array{callable(MonoClient): mixed, string[]}> */
    public function sensitiveQueryRequestProvider(): array
    {
        return [
            'card token' => [
                static fn (MonoClient $client) => $client->wallet()->deleteCard('SECRET-CARD-TOKEN'),
                ['SECRET-CARD-TOKEN'],
            ],
            'wallet id' => [
                static fn (MonoClient $client) => $client->wallet()->getCards('SECRET-WALLET-ID'),
                ['SECRET-WALLET-ID'],
            ],
            'receipt e-mail' => [
                static fn (MonoClient $client) => $client->invoices()->getReceipt('p2_1', 'buyer@example.com'),
                ['buyer%40example.com', 'buyer@example.com'],
            ],
        ];
    }

    public function testClientCachesServiceInstances(): void
    {
        $http = new FakeHttpClient();
        $client = new MonoClient('token', 'https://example.test/api/merchant', $http);

        self::assertSame($client->invoices(), $client->invoices());
        self::assertSame($client->webhooks(), $client->webhooks());
    }
}
