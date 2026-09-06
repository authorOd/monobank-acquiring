<?php

namespace Vladchornyi\Mono\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Exceptions\MonoTransportException;
use Vladchornyi\Mono\Http\CurlHttpClient;
use Vladchornyi\Mono\Http\HttpResponse;
use Vladchornyi\Mono\Tests\Support\ExposedCurlHttpClient;

final class CurlRetryTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('cURL is not installed.');
        }
    }

    public function testGetRetriesRetryableResponses(): void
    {
        $client = $this->sequenceClient(
            new HttpResponse(429, '{}', ['Retry-After' => '999999999999999999999999']),
            new HttpResponse(200, '{"ok":true}')
        );

        $response = $client->send('GET', 'https://example.test');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $client->calls);
    }

    public function testPostIsNotRetriedByDefault(): void
    {
        $client = $this->sequenceClient(
            new HttpResponse(429, '{}'),
            new HttpResponse(200, '{"ok":true}')
        );

        $response = $client->send('POST', 'https://example.test', [], ['amount' => 100]);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(1, $client->calls);
    }

    public function testUnsafeUserAgentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CurlHttpClient(['user_agent' => "library\r\nX-Injected: yes"]);
    }

    /**
     * @dataProvider invalidOptionProvider
     * @param array<string, mixed> $options
     */
    public function testInvalidOptionsAreRejected(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CurlHttpClient($options);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidOptionProvider(): array
    {
        return [
            'timeout' => [['timeout' => 0]],
            'negative retries' => [['max_retries' => -1]],
            'too many retries' => [['max_retries' => 11]],
            'excessive retry delay' => [['max_retry_delay_ms' => 300001]],
            'statuses are not an array' => [['retry_statuses' => '429']],
            'invalid status' => [['retry_statuses' => [99]]],
            'methods are not an array' => [['retry_methods' => 'GET']],
            'method is not a string' => [['retry_methods' => [123]]],
            'invalid method token' => [['retry_methods' => ['GET POST']]],
        ];
    }

    public function testInvalidUtf8BodyRaisesJsonExceptionBeforeIo(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);

        $this->expectException(MonoJsonException::class);
        $client->send('POST', 'https://example.test', [], ['invalid' => "\xB1\x31"]);
    }

    public function testTransportFailureIsRetriedForGet(): void
    {
        $client = new class () extends CurlHttpClient {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct([
                    'max_retries' => 1,
                    'retry_delay_ms' => 0,
                    'max_retry_delay_ms' => 0,
                ]);
            }

            /** @param array<string, string> $headers */
            protected function execute(string $method, string $url, array $headers, ?string $json): HttpResponse
            {
                ++$this->calls;

                if ($this->calls === 1) {
                    throw new MonoTransportException('temporary transport failure');
                }

                return new HttpResponse(200, '{}');
            }
        };

        self::assertSame(200, $client->send('GET', 'https://example.test')->getStatusCode());
        self::assertSame(2, $client->calls);
    }

    public function testRetryAfterParsingIsBounded(): void
    {
        $client = new ExposedCurlHttpClient([
            'retry_delay_ms' => 1,
            'max_retry_delay_ms' => 5000,
        ]);

        self::assertNull($client->exposeRetryDelay(null));
        self::assertNull($client->exposeRetryDelay('not-a-date'));
        self::assertSame(2000, $client->exposeRetryDelay('2'));
        self::assertSame(5000, $client->exposeRetryDelay('999999999999'));
        self::assertSame(0, $client->exposeRetryDelay('Thu, 01 Jan 1970 00:00:00 GMT'));
        self::assertSame(5000, $client->exposeRetryDelay('Thu, 01 Jan 2099 00:00:00 GMT'));

        $client->exposeWait(2, null);
        self::assertTrue(true);
    }

    /**
     * @return CurlHttpClient&object{calls: int}
     */
    private function sequenceClient(HttpResponse ...$responses): CurlHttpClient
    {
        return new class ($responses) extends CurlHttpClient {
            /** @var HttpResponse[] */
            private array $responses;

            public int $calls = 0;

            /** @param HttpResponse[] $responses */
            public function __construct(array $responses)
            {
                parent::__construct([
                    'max_retries' => 2,
                    'retry_delay_ms' => 0,
                    'max_retry_delay_ms' => 0,
                ]);
                $this->responses = $responses;
            }

            /** @param array<string, string> $headers */
            protected function execute(string $method, string $url, array $headers, ?string $json): HttpResponse
            {
                ++$this->calls;
                $response = array_shift($this->responses);

                if (!$response instanceof HttpResponse) {
                    throw new RuntimeException('No response remains.');
                }

                return $response;
            }
        };
    }
}
