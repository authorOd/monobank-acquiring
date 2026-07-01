<?php

namespace Vladchornyi\Mono\Webhooks;

use Vladchornyi\Mono\Exceptions\MonoWebhookException;

class WebhookVerifier
{
    public function verify(string $payload, string $xSignBase64, string $publicKeyBase64): bool
    {
        if ($payload === '') {
            throw new MonoWebhookException('Webhook payload is empty');
        }

        $signature = base64_decode($xSignBase64, true);
        if ($signature === false) {
            throw new MonoWebhookException('Webhook signature is not valid base64');
        }

        $publicKeyPem = base64_decode($publicKeyBase64, true);
        if ($publicKeyPem === false) {
            throw new MonoWebhookException('Webhook public key is not valid base64');
        }

        $publicKey = openssl_get_publickey($publicKeyPem);
        if ($publicKey === false) {
            throw new MonoWebhookException('Unable to read Monobank public key');
        }

        $result = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if (is_resource($publicKey)) {
            openssl_free_key($publicKey);
        }

        if ($result === -1) {
            throw new MonoWebhookException('OpenSSL failed to verify webhook signature');
        }

        return $result === 1;
    }
}
