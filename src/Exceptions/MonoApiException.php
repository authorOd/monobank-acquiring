<?php

namespace Vladchornyi\Mono\Exceptions;

use Vladchornyi\Mono\Support\SensitiveData;

class MonoApiException extends MonoException
{
    protected int $statusCode;
    protected string $method;
    protected string $url;
    protected string $responseBody;
    /** @var array<array-key, mixed>|null */
    protected ?array $responseData;

    /** @var array<string, string> */
    protected array $responseHeaders;

    /**
     * The exception message contains only the HTTP status, method, and URL
     * path. Query parameters (card tokens, wallet ids, e-mails) are excluded so
     * default exception loggers never record them.
     *
     * @param array<array-key, mixed>|null $responseData
     * @param array<string, string> $responseHeaders
     */
    public function __construct(
        int $statusCode,
        string $method,
        string $url,
        string $responseBody,
        ?array $responseData = null,
        array $responseHeaders = []
    ) {
        $message = sprintf(
            'Monobank API error %d for %s %s',
            $statusCode,
            $method,
            SensitiveData::urlPath($url)
        );

        parent::__construct($message, $statusCode);

        $this->statusCode = $statusCode;
        $this->method = $method;
        $this->url = $url;
        $this->responseBody = $responseBody;
        $this->responseData = $responseData;
        $this->responseHeaders = $responseHeaders;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The full request URL including the raw query string. It may contain
     * sensitive identifiers; prefer getSafeUrl() or getPath() for logging.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Request URL with sensitive query values redacted.
     */
    public function getSafeUrl(): string
    {
        return SensitiveData::sanitizeUrl($this->url);
    }

    /**
     * Request path without the query string.
     */
    public function getPath(): string
    {
        return SensitiveData::urlPath($this->url);
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    /**
     * @return array<string, string>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseHeaderLine(string $name): ?string
    {
        foreach ($this->responseHeaders as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Monobank error code from the response body (for example `BAD_REQUEST`, `TMR`).
     */
    public function getErrorCode(): ?string
    {
        $errCode = $this->responseData['errCode'] ?? null;

        return is_string($errCode) && $errCode !== '' ? $errCode : null;
    }

    /**
     * Diagnostic context that is safe to pass to a logger as-is: no query
     * values, headers, or response payload.
     *
     * @return array{
     *     status: int,
     *     method: string,
     *     path: string,
     *     safe_url: string,
     *     request_id: string|null,
     *     err_code: string|null,
     *     retry_after: string|null
     * }
     */
    public function getSafeContext(): array
    {
        return [
            'status' => $this->statusCode,
            'method' => $this->method,
            'path' => $this->getPath(),
            'safe_url' => $this->getSafeUrl(),
            'request_id' => $this->getResponseHeaderLine('X-Request-Id'),
            'err_code' => $this->getErrorCode(),
            'retry_after' => $this->getResponseHeaderLine('Retry-After'),
        ];
    }
}
