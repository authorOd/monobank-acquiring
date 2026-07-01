<?php

namespace Vladchornyi\Mono\Exceptions;

class MonoApiException extends MonoException
{
    protected int $statusCode;
    protected string $method;
    protected string $url;
    protected string $responseBody;
    protected ?array $responseData;

    public function __construct(
        int $statusCode,
        string $method,
        string $url,
        string $responseBody,
        ?array $responseData = null
    ) {
        $message = sprintf('Monobank API error %d for %s %s', $statusCode, $method, $url);

        parent::__construct($message, $statusCode);

        $this->statusCode = $statusCode;
        $this->method = $method;
        $this->url = $url;
        $this->responseBody = $responseBody;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
