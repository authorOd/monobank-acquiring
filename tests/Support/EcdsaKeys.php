<?php

namespace Vladchornyi\Mono\Tests\Support;

use RuntimeException;

final class EcdsaKeys
{
    /**
     * @return array{0: \OpenSSLAsymmetricKey, 1: string} private key and base64 PEM public key
     */
    public static function pair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;

        if ($key === false || !is_array($details)) {
            throw new RuntimeException('Unable to generate an EC key pair.');
        }

        return [$key, base64_encode((string) $details['key'])];
    }

    /**
     * @param \OpenSSLAsymmetricKey $privateKey
     */
    public static function sign(string $payload, $privateKey): string
    {
        if (!openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the payload.');
        }

        return base64_encode($signature);
    }
}
