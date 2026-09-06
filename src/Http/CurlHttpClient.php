<?php

namespace Vladchornyi\Mono\Http;

use InvalidArgumentException;
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Exceptions\MonoTransportException;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Support\PackageVersion;

class CurlHttpClient implements HttpClientInterface
{
    protected ?\CurlHandle $handle = null;

    protected int $timeout;
    protected int $connectTimeout;
    protected bool $verifyPeer;
    protected string $userAgent;
    protected int $maxRetries;
    protected int $retryDelayMs;
    protected int $maxRetryDelayMs;

    /** @var int[] */
    protected array $retryStatusCodes;

    /** @var string[] */
    protected array $retryMethods;

    /**
     * @param array{
     *     timeout?: int,
     *     connect_timeout?: int,
     *     verify_peer?: bool,
     *     user_agent?: string,
     *     max_retries?: int,
     *     retry_delay_ms?: int,
     *     max_retry_delay_ms?: int,
     *     retry_statuses?: int[],
     *     retry_methods?: string[]
     * } $options
     */
    public function __construct(array $options = [])
    {
        if (!extension_loaded('curl')) {
            throw new MonoTransportException(
                'The cURL extension is required by CurlHttpClient. Install ext-curl or inject a custom HTTP client.'
            );
        }

        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 10);
        $this->verifyPeer = (bool) ($options['verify_peer'] ?? true);
        $this->userAgent = (string) ($options['user_agent'] ?? 'vladchornyi-mono/' . PackageVersion::get());
        $this->maxRetries = (int) ($options['max_retries'] ?? 2);
        $this->retryDelayMs = (int) ($options['retry_delay_ms'] ?? 200);
        $this->maxRetryDelayMs = (int) ($options['max_retry_delay_ms'] ?? 5000);
        $this->retryStatusCodes = $this->normalizeRetryStatusCodes(
            $options['retry_statuses'] ?? [429, 502, 503, 504]
        );
        $this->retryMethods = $this->normalizeRetryMethods(
            $options['retry_methods'] ?? ['GET', 'HEAD', 'OPTIONS']
        );

        if ($this->timeout <= 0 || $this->connectTimeout <= 0) {
            throw new InvalidArgumentException('HTTP timeout values must be positive integers.');
        }

        if ($this->maxRetries < 0 || $this->retryDelayMs < 0 || $this->maxRetryDelayMs < 0) {
            throw new InvalidArgumentException('HTTP retry values must be non-negative integers.');
        }

        if ($this->maxRetries > 10 || $this->maxRetryDelayMs > 300000) {
            throw new InvalidArgumentException(
                'HTTP retries are limited to 10 attempts and a maximum delay of 300000 milliseconds.'
            );
        }

        if (trim($this->userAgent) === '' || preg_match('/[\x00-\x1F\x7F]/', $this->userAgent) === 1) {
            throw new InvalidArgumentException('HTTP user agent must be non-empty and contain no control characters.');
        }
    }

    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        InputValidator::httpUrl($url, 'url');

        $method = $this->normalizeMethod($method);

        if (in_array($method, ['GET', 'HEAD'], true) && $body !== null) {
            throw new InvalidArgumentException("{$method} requests must not contain a JSON body.");
        }

        $json = $this->encodeBody($body);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->execute($method, $url, $headers, $json);
            } catch (MonoTransportException $e) {
                if (!$this->canRetry($method, $attempt)) {
                    throw $e;
                }

                $this->waitBeforeRetry($attempt, null);
                ++$attempt;
                continue;
            }

            if (!$this->shouldRetryResponse($method, $response, $attempt)) {
                return $response;
            }

            $this->waitBeforeRetry($attempt, $response->getHeaderLine('Retry-After'));
            ++$attempt;
        }
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    protected function execute(string $method, string $url, array $headers, ?string $json): HttpResponse
    {
        if ($this->handle === null) {
            $handle = curl_init();

            if ($handle === false) {
                throw new MonoTransportException('Failed to initialize cURL.');
            }

            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }

        $ch = $this->handle;

        $normalizedHeaders = $this->normalizeHeaders($headers);
        $responseHeaders = [];

        $configured = curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);

                if ($trimmed === '') {
                    return $length;
                }

                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                    return $length;
                }

                $parts = explode(':', $line, 2);
                if (count($parts) !== 2) {
                    return $length;
                }

                $name = trim($parts[0]);
                $value = trim($parts[1]);

                if ($name !== '') {
                    if (isset($responseHeaders[$name])) {
                        $responseHeaders[$name] .= ', ' . $value;
                    } else {
                        $responseHeaders[$name] = $value;
                    }
                }

                return $length;
            },
        ]);

        if ($configured === false) {
            throw new MonoTransportException('Failed to configure cURL request.');
        }

        if ($json !== null && curl_setopt($ch, CURLOPT_POSTFIELDS, $json) === false) {
            throw new MonoTransportException('Failed to configure cURL request body.');
        }

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        if ($curlErrno !== 0) {
            throw new MonoTransportException("cURL error ({$curlErrno}): {$curlError}");
        }

        if (!is_string($response)) {
            throw new MonoTransportException('Failed to execute cURL request.');
        }

        return new HttpResponse($statusCode, $response, $responseHeaders);
    }

    /**
     * @param array<array-key, mixed>|null $body
     */
    protected function encodeBody(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        try {
            return json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $e) {
            throw new MonoJsonException('Failed to encode request data to JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return string[]
     */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (
                !is_string($name)
                || !is_string($value)
                || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $name) !== 1
            ) {
                throw new InvalidArgumentException('HTTP headers must be a non-empty string-to-string map.');
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('HTTP header values must not contain control characters.');
            }

            $normalized[] = $name . ': ' . $value;
        }

        return $normalized;
    }

    /**
     * @param mixed $statusCodes
     * @return int[]
     */
    protected function normalizeRetryStatusCodes($statusCodes): array
    {
        if (!is_array($statusCodes)) {
            throw new InvalidArgumentException('HTTP retry statuses must be an array.');
        }

        $normalized = [];

        foreach ($statusCodes as $statusCode) {
            if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
                throw new InvalidArgumentException('HTTP retry statuses must contain valid integer status codes.');
            }

            $normalized[] = $statusCode;
        }

        return $normalized;
    }

    /**
     * @param mixed $methods
     * @return string[]
     */
    protected function normalizeRetryMethods($methods): array
    {
        if (!is_array($methods)) {
            throw new InvalidArgumentException('HTTP retry methods must be an array.');
        }

        $normalized = [];

        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new InvalidArgumentException('HTTP retry methods must contain valid method names.');
            }

            $normalized[] = $this->normalizeMethod($method);
        }

        return $normalized;
    }

    protected function normalizeMethod(string $method): string
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D', $method) !== 1) {
            throw new InvalidArgumentException('HTTP method must be a non-empty RFC token.');
        }

        return strtoupper($method);
    }

    protected function shouldRetryResponse(string $method, HttpResponse $response, int $attempt): bool
    {
        return $this->canRetry($method, $attempt)
            && in_array($response->getStatusCode(), $this->retryStatusCodes, true);
    }

    protected function canRetry(string $method, int $attempt): bool
    {
        return $attempt < $this->maxRetries && in_array($method, $this->retryMethods, true);
    }

    protected function waitBeforeRetry(int $attempt, ?string $retryAfter): void
    {
        $delayMs = $this->retryDelayFromHeader($retryAfter);

        if ($delayMs === null) {
            $delayMs = min($this->retryDelayMs, $this->maxRetryDelayMs);

            for ($step = 0; $step < $attempt && $delayMs < $this->maxRetryDelayMs; ++$step) {
                $delayMs = min($delayMs * 2, $this->maxRetryDelayMs);
            }
        }

        $delayMs = min($delayMs, $this->maxRetryDelayMs);

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    protected function retryDelayFromHeader(?string $retryAfter): ?int
    {
        if ($retryAfter === null || $retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            if (strlen($retryAfter) > 9) {
                return $this->maxRetryDelayMs;
            }

            $seconds = (int) $retryAfter;
            if ($seconds > intdiv($this->maxRetryDelayMs, 1000)) {
                return $this->maxRetryDelayMs;
            }

            return $seconds * 1000;
        }

        $retryAt = strtotime($retryAfter);
        if ($retryAt === false) {
            return null;
        }

        $seconds = max(0, $retryAt - time());
        if ($seconds > intdiv($this->maxRetryDelayMs, 1000)) {
            return $this->maxRetryDelayMs;
        }

        return $seconds * 1000;
    }
}
