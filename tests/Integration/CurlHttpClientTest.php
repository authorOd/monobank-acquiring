<?php

namespace Vladchornyi\Mono\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\Http\CurlHttpClient;

final class CurlHttpClientTest extends TestCase
{
    /** @var resource|null */
    private $serverProcess;

    private int $port;

    protected function setUp(): void
    {
        if (!extension_loaded('curl') || !function_exists('proc_open')) {
            self::markTestSkipped('cURL and proc_open are required for this integration test.');
        }

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            self::markTestSkipped('The environment does not permit a local TCP fixture: ' . $errorMessage);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        $this->port = (int) substr(strrchr($address, ':'), 1);

        $router = dirname(__DIR__) . '/fixtures/http_server.php';
        $serverBinary = getenv('MONO_TEST_PHP_BINARY');
        if (!is_string($serverBinary) || $serverBinary === '') {
            $serverBinary = PHP_BINARY;
        }

        $this->serverProcess = proc_open(
            [$serverBinary, '-S', '127.0.0.1:' . $this->port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes
        );
        self::assertIsResource($this->serverProcess);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $this->port);

            if (is_resource($connection)) {
                fclose($connection);
                return;
            }

            usleep(20000);
        }

        self::fail('Local HTTP fixture did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }
    }

    public function testDefaultTransportSendsJsonAndCapturesHeaders(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);
        $response = $client->send(
            'POST',
            'http://127.0.0.1:' . $this->port,
            ['Content-Type' => 'application/json'],
            ['amount' => 100]
        );
        $decoded = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('POST', $decoded['method']);
        self::assertSame('application/json', $decoded['headers']['Content-Type']);
        self::assertSame('{"amount":100}', $decoded['body']);
        self::assertSame('local-integration-test', $response->getHeaderLine('x-request-id'));
    }

    public function testDestroyingAUsedClientEmitsNoDeprecation(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);
        $client->send('GET', 'http://127.0.0.1:' . $this->port);
        $client->send('GET', 'http://127.0.0.1:' . $this->port);
        $notices = [];

        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        });

        try {
            // PHP 8.5 deprecates curl_close(); the destructor must not call it.
            $client->__destruct();
            unset($client);
            gc_collect_cycles();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $notices);
    }

    public function testDefaultTransportRejectsMalformedHeaderMaps(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $client->send('GET', 'http://127.0.0.1:' . $this->port, ['Content-Type: application/json']);
    }

    public function testHeadUsesHeadSemanticsWithoutRequestOrResponseBody(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);
        $response = $client->send('HEAD', 'http://127.0.0.1:' . $this->port);

        self::assertSame('', $response->getBody());
        self::assertSame('HEAD', $response->getHeaderLine('X-Seen-Method'));
        self::assertSame('0', $response->getHeaderLine('X-Request-Body-Length'));
    }

    public function testGetRejectsAJsonBody(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GET requests must not contain');
        $client->send('GET', 'http://127.0.0.1:' . $this->port, [], ['unexpected' => true]);
    }

    /**
     * @dataProvider invalidMethodProvider
     */
    public function testTransportRejectsMethodInjection(string $method): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RFC token');
        $client->send($method, 'http://127.0.0.1:' . $this->port);
    }

    /** @return array<string, array{string}> */
    public function invalidMethodProvider(): array
    {
        return [
            'space' => ['GET POST'],
            'newline' => ["GET\r\nX-Injected: yes"],
            'empty' => [''],
        ];
    }

    public function testTransportRejectsControlCharactersInHeaderValues(): void
    {
        $client = new CurlHttpClient(['max_retries' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $client->send('GET', 'http://127.0.0.1:' . $this->port, ['X-Test' => "safe\0unsafe"]);
    }
}
