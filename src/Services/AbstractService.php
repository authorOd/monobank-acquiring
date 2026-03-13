<?php

namespace Vladchornyi\Mono\Services;

abstract class AbstractService
{
    protected $apiKey;
    protected $baseUrl;

    /**
     * @param string $apiKey
     */
    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    protected function sendRequest(string $method, string $endpoint, array $data = [])
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \Exception('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Token: ' . $this->apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if (!empty($data)) {
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                curl_close($ch);
                throw new \Exception('Failed to encode request data to JSON');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new \Exception("cURL error ({$curlErrno}): {$curlError}");
        }

        if ($response === false) {
            throw new \Exception('Failed to execute cURL request');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \Exception("HTTP error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to decode JSON response: ' . json_last_error_msg());
        }

        return $decoded;
    }
}