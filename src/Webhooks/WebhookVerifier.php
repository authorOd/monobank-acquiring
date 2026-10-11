<?php

namespace Vladchornyi\Mono\Webhooks;

use Vladchornyi\Mono\Exceptions\MonoWebhookException;

class WebhookVerifier
{
    private const MAX_SIGNATURE_BYTES = 144;

    public function verify(
        #[\SensitiveParameter] string $payload,
        #[\SensitiveParameter] string $xSignBase64,
        string $publicKeyBase64
    ): bool {
        $this->assertPayload($payload);
        $signature = $this->decodeSignature($xSignBase64);
        $publicKey = $this->decodePublicKey($publicKeyBase64);

        $result = openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($result === -1) {
            throw new MonoWebhookException('OpenSSL failed to verify webhook signature');
        }

        return $result === 1;
    }

    public function assertPayload(#[\SensitiveParameter] string $payload): void
    {
        if ($payload === '') {
            throw new MonoWebhookException('Webhook payload is empty.');
        }
    }

    public function assertSignature(#[\SensitiveParameter] string $xSignBase64): void
    {
        $this->decodeSignature($xSignBase64);
    }

    public function assertPublicKey(string $publicKeyBase64): void
    {
        $this->decodePublicKey($publicKeyBase64);
    }

    private function requireOpenSsl(): void
    {
        if (!extension_loaded('openssl')) {
            throw new MonoWebhookException(
                'The OpenSSL extension is required to verify webhooks. Install ext-openssl or verify externally.'
            );
        }
    }

    private function decodeSignature(#[\SensitiveParameter] string $xSignBase64): string
    {
        if ($xSignBase64 === '') {
            throw new MonoWebhookException('Webhook signature is missing.');
        }

        $signature = base64_decode($xSignBase64, true);

        if (
            $signature === false
            || $signature === ''
            || strlen($signature) > self::MAX_SIGNATURE_BYTES
            || !$this->isCanonicalEcdsaDerSignature($signature)
        ) {
            throw new MonoWebhookException('Webhook signature is not a valid Base64-encoded DER signature.');
        }

        return $signature;
    }

    private function isCanonicalEcdsaDerSignature(string $signature): bool
    {
        $offset = 0;
        $totalLength = strlen($signature);

        if (!$this->consumeDerTag($signature, $offset, 0x30)) {
            return false;
        }

        $sequenceLength = $this->readDerLength($signature, $offset);
        if ($sequenceLength === null || $sequenceLength !== $totalLength - $offset) {
            return false;
        }

        $offset = $this->readCanonicalPositiveDerInteger($signature, $offset);
        if ($offset === null) {
            return false;
        }

        $offset = $this->readCanonicalPositiveDerInteger($signature, $offset);
        if ($offset === null) {
            return false;
        }

        return $offset === $totalLength;
    }

    private function readCanonicalPositiveDerInteger(string $der, int $offset): ?int
    {
        if (!$this->consumeDerTag($der, $offset, 0x02)) {
            return null;
        }

        $length = $this->readDerLength($der, $offset);
        if ($length === null || $length === 0 || $offset + $length > strlen($der)) {
            return null;
        }

        $first = ord($der[$offset]);

        if (($first & 0x80) !== 0 || ($length === 1 && $first === 0)) {
            return null;
        }

        if ($length > 1 && $first === 0 && (ord($der[$offset + 1]) & 0x80) === 0) {
            return null;
        }

        return $offset + $length;
    }

    private function consumeDerTag(string $der, int &$offset, int $expectedTag): bool
    {
        if ($offset >= strlen($der) || ord($der[$offset]) !== $expectedTag) {
            return false;
        }

        ++$offset;

        return true;
    }

    private function readDerLength(string $der, int &$offset): ?int
    {
        $totalLength = strlen($der);
        if ($offset >= $totalLength) {
            return null;
        }

        $first = ord($der[$offset]);
        ++$offset;

        if ($first < 0x80) {
            return $first;
        }

        $lengthBytes = $first & 0x7F;
        if ($lengthBytes === 0 || $lengthBytes > 2 || $offset + $lengthBytes > $totalLength) {
            return null;
        }

        if (ord($der[$offset]) === 0) {
            return null;
        }

        $length = 0;
        for ($index = 0; $index < $lengthBytes; ++$index) {
            $length = ($length << 8) | ord($der[$offset]);
            ++$offset;
        }

        if ($length < 0x80 || $length > self::MAX_SIGNATURE_BYTES) {
            return null;
        }

        return $length;
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private function decodePublicKey(string $publicKeyBase64)
    {
        $this->requireOpenSsl();

        $publicKeyPem = base64_decode($publicKeyBase64, true);
        if ($publicKeyPem === false || $publicKeyPem === '') {
            throw new MonoWebhookException('Webhook public key is not valid base64.');
        }

        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            throw new MonoWebhookException('Unable to read Monobank public key.');
        }

        $details = openssl_pkey_get_details($publicKey);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new MonoWebhookException('Monobank public key must be an EC public key.');
        }

        return $publicKey;
    }
}
