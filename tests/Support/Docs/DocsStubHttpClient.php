<?php

namespace Vladchornyi\Mono\Tests\Support\Docs;

use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Http\HttpResponse;

/**
 * Answers every request with one plausible JSON document so any documentation
 * example can run without reaching Monobank.
 */
final class DocsStubHttpClient implements HttpClientInterface
{
    private string $publicKey;

    public function __construct(string $publicKey)
    {
        $this->publicKey = $publicKey;
    }

    public function send(string $method, string $url, array $headers = [], ?array $body = null): HttpResponse
    {
        return new HttpResponse(200, json_encode([
            'invoiceId' => 'p2_1',
            'pageUrl' => 'https://pay.mbnk.biz/p2_1',
            'appUrl' => 'monobank://p2_1',
            'subscriptionId' => 's1',
            'status' => 'success',
            'merchantId' => 'm1',
            'merchantName' => 'Example',
            'key' => $this->publicKey,
            'list' => [[
                'code' => 'term-1',
                'splitReceiverId' => 'recv-1',
                'subscriptionId' => 's1',
                'status' => 'active',
                'amount' => 59000,
            ]],
            'wallet' => [['cardToken' => 'tok', 'maskedPan' => '444403******1902']],
            'file' => base64_encode('%PDF-1.4'),
            'checks' => [],
            'payments' => [],
            'pagination' => ['totalItems' => 0],
            'cancelList' => [],
        ], JSON_THROW_ON_ERROR));
    }
}
