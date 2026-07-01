<?php

namespace Vladchornyi\Mono\Http;

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Exceptions\MonoTransportException;

class CurlHttpClient implements HttpClientInterface
{
    protected int $timeout;
    protected int $connectTimeout;
    protected bool $verifyPeer;
    protected string $userAgent;

    /**
     * @param array{timeout?: int, connect_timeout?: int, verify_peer?: bool, user_agent?: string} $options
     */
    public function __construct(array $options = [])
    {
        $this->timeout = (int) ($options['timeout'] ?? 30);
        $this->connectTimeout = (int) ($options['connect_timeout'] ?? 10);
        $this->verifyPeer = (bool) ($options['verify_peer'] ?? true);
        $this->userAgent = (string) ($options['user_agent'] ?? 'vladchornyi-mono/1.4');
    }

    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new MonoTransportException('Failed to initialize cURL');
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalizedHeaders[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $normalizedHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);

        if ($body !== null) {
            try {
                $json = json_encode(
                    $body,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                );
            } catch (\JsonException $e) {
                curl_close($ch);
                throw new MonoJsonException('Failed to encode request data to JSON: ' . $e->getMessage(), 0, $e);
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new MonoTransportException("cURL error ({$curlErrno}): {$curlError}");
        }

        if ($response === false) {
            throw new MonoTransportException('Failed to execute cURL request');
        }

        return new HttpResponse($statusCode, $response);
    }
}
