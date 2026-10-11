<?php

namespace Vladchornyi\Mono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Throwable;
use Vladchornyi\Mono\Contracts\HttpClientInterface;
use Vladchornyi\Mono\Exceptions\MonoTransportException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Http\CurlHttpClient;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\Models\SaveCardData;
use Vladchornyi\Mono\Models\WalletPaymentData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Services\AbstractService;
use Vladchornyi\Mono\Services\InvoiceService;
use Vladchornyi\Mono\Services\WalletService;
use Vladchornyi\Mono\Services\WebhookService;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Support\SensitiveData;
use Vladchornyi\Mono\Tests\Support\EcdsaKeys;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

/**
 * Exception traces keep call arguments unless `zend.exception_ignore_args=On`
 * (the default without a php.ini, e.g. in official Docker images). Error
 * trackers serialize those arguments, so every parameter that receives the
 * merchant token, card data, or a raw webhook body is marked
 * `#[\SensitiveParameter]`. PHP 8.2+ replaces such values in traces; PHP 8.0
 * and 8.1 ignore the attribute.
 */
final class SensitiveParameterTest extends TestCase
{
    private const SECRET = 'uSECRET-MERCHANT-TOKEN-0123456789abcdef';
    private const CARD_TOKEN = 'CARD-TOKEN-0123456789abcdef';

    /**
     * @dataProvider sensitiveParameterProvider
     */
    public function testSecretBearingParameterIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $names = [];

        foreach ($reflection->getParameters() as $candidate) {
            if ($candidate->getName() !== $parameter) {
                continue;
            }

            foreach ($candidate->getAttributes() as $attribute) {
                $names[] = $attribute->getName();
            }
        }

        self::assertContains('SensitiveParameter', $names, "{$class}::{$method}(\${$parameter})");
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function sensitiveParameterProvider(): array
    {
        $cases = [
            [MonoClient::class, '__construct', 'apiKey'],
            [AbstractService::class, '__construct', 'apiKey'],
            [AbstractService::class, 'sendRequest', 'data'],
            [AbstractService::class, 'sendRequest', 'query'],
            [AbstractService::class, 'buildUrl', 'query'],
            [HttpClientInterface::class, 'send', 'url'],
            [HttpClientInterface::class, 'send', 'headers'],
            [HttpClientInterface::class, 'send', 'body'],
            [CurlHttpClient::class, 'send', 'url'],
            [CurlHttpClient::class, 'send', 'headers'],
            [CurlHttpClient::class, 'send', 'body'],
            [CurlHttpClient::class, 'execute', 'url'],
            [CurlHttpClient::class, 'execute', 'headers'],
            [CurlHttpClient::class, 'execute', 'json'],
            [CurlHttpClient::class, 'encodeBody', 'body'],
            [CurlHttpClient::class, 'normalizeHeaders', 'headers'],
            [InputValidator::class, 'httpUrl', 'value'],
            [SensitiveData::class, 'sanitizeHeaders', 'headers'],
            [WalletService::class, 'getCards', 'walletId'],
            [WalletService::class, 'deleteCard', 'cardToken'],
            [InvoiceService::class, 'getReceipt', 'email'],
            [WalletPaymentData::class, '__construct', 'cardToken'],
            [SaveCardData::class, '__construct', 'walletId'],
            [SaveCardData::class, 'fromArray', 'data'],
            [MerchantPaymInfoItem::class, '__construct', 'customerEmails'],
            [WebhookService::class, 'verify', 'payload'],
            [WebhookService::class, 'verify', 'xSignBase64'],
            [WebhookService::class, 'parse', 'payload'],
            [WebhookService::class, 'parseVerified', 'payload'],
            [WebhookService::class, 'parseVerified', 'xSignBase64'],
            [WebhookService::class, 'payload', 'payload'],
            [WebhookService::class, 'parsePayload', 'payload'],
            [WebhookService::class, 'parseVerifiedPayload', 'payload'],
            [WebhookService::class, 'parseVerifiedPayload', 'xSignBase64'],
            [WebhookService::class, 'shouldApply', 'payload'],
            [WebhookService::class, 'getModifiedInstant', 'payload'],
            [WebhookService::class, 'getModifiedDate', 'payload'],
            [WebhookVerifier::class, 'verify', 'payload'],
            [WebhookVerifier::class, 'verify', 'xSignBase64'],
            [WebhookVerifier::class, 'assertPayload', 'payload'],
            [WebhookVerifier::class, 'assertSignature', 'xSignBase64'],
            [WebhookPayload::class, '__construct', 'data'],
            [WebhookPayload::class, 'fromArray', 'data'],
        ];

        $named = [];
        foreach ($cases as [$class, $method, $parameter]) {
            $short = substr((string) strrchr($class, '\\'), 1);
            $named["{$short}::{$method}(\${$parameter})"] = [$class, $method, $parameter];
        }

        return $named;
    }

    public function testMerchantTokenIsAbsentFromATransportFailureTrace(): void
    {
        if (!extension_loaded('curl')) {
            self::assertFalse(class_exists(\CurlHandle::class, false));
            return;
        }

        $exception = $this->withTraceArguments(static function (): void {
            $mono = new MonoClient(
                self::SECRET,
                'http://127.0.0.1:1',
                httpOptions: ['connect_timeout' => 1, 'timeout' => 1, 'max_retries' => 0]
            );
            $mono->wallet()->deleteCard(self::CARD_TOKEN);
        });

        self::assertInstanceOf(MonoTransportException::class, $exception);
        $this->assertTraceHides($exception, [self::SECRET, self::CARD_TOKEN]);
    }

    public function testMerchantTokenIsAbsentFromAConstructorValidationTrace(): void
    {
        $exception = $this->withTraceArguments(static function (): void {
            new MonoClient(self::SECRET, 'ftp://api.monobank.ua/api/merchant');
        });

        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertTraceHides($exception, [self::SECRET]);
    }

    public function testWebhookBodyIsAbsentFromASignatureFailureTrace(): void
    {
        if (!extension_loaded('openssl')) {
            self::assertFalse(function_exists('openssl_verify'));
            return;
        }

        [, $publicKey] = EcdsaKeys::pair();
        [$otherPrivateKey] = EcdsaKeys::pair();
        $body = json_encode([
            'invoiceId' => 'p2_1',
            'status' => 'success',
            'walletData' => ['walletId' => 'customer-42', 'cardToken' => self::CARD_TOKEN, 'status' => 'created'],
        ], JSON_THROW_ON_ERROR);
        $signature = EcdsaKeys::sign($body, $otherPrivateKey);

        $exception = $this->withTraceArguments(static function () use ($body, $signature, $publicKey): void {
            WebhookService::create()->parseVerifiedPayload($body, $signature, $publicKey);
        });

        self::assertInstanceOf(MonoWebhookException::class, $exception);
        $this->assertTraceHides($exception, [self::CARD_TOKEN, $signature]);
    }

    private function withTraceArguments(callable $callback): Throwable
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        self::fail('The scenario did not throw.');
    }

    /**
     * @param string[] $secrets
     */
    private function assertTraceHides(Throwable $exception, array $secrets): void
    {
        $libraryArguments = [];

        foreach ($exception->getTrace() as $frame) {
            $class = $frame['class'] ?? '';

            $isLibrary = str_starts_with($class, 'Vladchornyi\\Mono\\')
                && !str_starts_with($class, 'Vladchornyi\\Mono\\Tests\\');

            if ($isLibrary) {
                $libraryArguments[] = $frame['args'] ?? [];
            }
        }

        self::assertNotEmpty($libraryArguments, 'the trace must contain library frames');

        foreach ($secrets as $secret) {
            $leaked = $this->contains($libraryArguments, $secret, 0);

            if (PHP_VERSION_ID < 80200) {
                // PHP 8.0 and 8.1 ignore the attribute; zend.exception_ignore_args=On is the only protection.
                self::assertTrue($leaked, 'expected PHP < 8.2 to keep trace arguments');
                continue;
            }

            self::assertFalse($leaked, 'a secret value leaked into exception trace arguments');
        }
    }

    /**
     * @param mixed $value
     */
    private function contains($value, string $secret, int $depth): bool
    {
        if (is_string($value)) {
            return str_contains($value, $secret);
        }

        if (!is_array($value) || $depth > 8) {
            return false;
        }

        foreach ($value as $item) {
            if ($this->contains($item, $secret, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
