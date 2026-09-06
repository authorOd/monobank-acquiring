<?php

namespace Vladchornyi\Mono\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Vladchornyi\Mono\Support\Instant;
use Vladchornyi\Mono\Contracts\PublicKeyProviderInterface;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Support\Rfc3339;
use Vladchornyi\Mono\Webhooks\ArrayPublicKeyCache;
use Vladchornyi\Mono\Webhooks\CachedPublicKeyProvider;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

class WebhookService
{
    protected WebhookVerifier $verifier;
    protected ?PublicKeyProviderInterface $publicKeyProvider;

    /**
     * The constructor keeps the 1.x signature so every 1.x call form keeps
     * working: positional `new WebhookService($pubkey, $verifier)`, named
     * `new WebhookService(pubkeyService: $pubkey, verifier: $verifier)`, and
     * mixed `new WebhookService($pubkey, verifier: $verifier)`. Passing a
     * PubkeyService wraps it in a CachedPublicKeyProvider over the
     * process-shared fallback cache and emits a deprecation notice; it is
     * removed in 3.0.
     *
     * New code should use `WebhookService::create()` or `MonoClient::webhooks()`.
     */
    public function __construct(
        ?PubkeyService $pubkeyService = null,
        ?WebhookVerifier $verifier = null,
        ?PublicKeyProviderInterface $publicKeyProvider = null
    ) {
        if ($pubkeyService !== null && $publicKeyProvider !== null) {
            throw new \InvalidArgumentException(
                'Pass either the deprecated pubkeyService or a publicKeyProvider to WebhookService, not both.'
            );
        }

        if ($pubkeyService !== null) {
            @trigger_error(
                'Passing PubkeyService to WebhookService::__construct() is deprecated since 2.0; '
                . 'use WebhookService::create($publicKeyProvider) or MonoClient::webhooks().',
                E_USER_DEPRECATED
            );

            $publicKeyProvider = new CachedPublicKeyProvider(
                $pubkeyService,
                cache: ArrayPublicKeyCache::processShared(),
                verifier: $verifier
            );
        }

        $this->verifier = $verifier ?? new WebhookVerifier();
        $this->publicKeyProvider = $publicKeyProvider;
    }

    /**
     * Preferred 2.0 factory. Without a provider, automatic public-key fetching
     * fails closed and only explicit-key verification is available.
     */
    public static function create(
        ?PublicKeyProviderInterface $publicKeyProvider = null,
        ?WebhookVerifier $verifier = null
    ): self {
        return new self(null, $verifier, $publicKeyProvider);
    }

    public function getPublicKey(): string
    {
        return $this->requirePublicKeyProvider()->getPublicKey();
    }

    public function refreshPublicKey(): string
    {
        return $this->requirePublicKeyProvider()->refreshPublicKey();
    }

    public function verify(string $payload, ?string $xSignBase64, ?string $publicKeyBase64 = null): bool
    {
        if ($xSignBase64 === null || $xSignBase64 === '') {
            throw new MonoWebhookException('Webhook signature is missing.');
        }

        $this->verifier->assertPayload($payload);
        $this->verifier->assertSignature($xSignBase64);

        if ($publicKeyBase64 !== null) {
            return $this->verifier->verify($payload, $xSignBase64, $publicKeyBase64);
        }

        if ($this->verifier->verify($payload, $xSignBase64, $this->getPublicKey())) {
            return true;
        }

        return $this->verifier->verify($payload, $xSignBase64, $this->refreshPublicKey());
    }

    private function requirePublicKeyProvider(): PublicKeyProviderInterface
    {
        if ($this->publicKeyProvider === null) {
            throw new MonoWebhookException(
                'Automatic public-key fetching requires a PublicKeyProviderInterface; '
                . 'use MonoClient::webhooks() or pass an explicit public key to the verification method.'
            );
        }

        return $this->publicKeyProvider;
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $payload): array
    {
        $trimmedPayload = ltrim($payload);

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MonoWebhookException('Failed to decode webhook JSON: ' . $e->getMessage(), 0, $e);
        }

        if ($trimmedPayload === '' || $trimmedPayload[0] !== '{' || !is_array($decoded)) {
            throw new MonoWebhookException('Webhook JSON payload must be an object.');
        }

        if ($decoded === []) {
            throw new MonoWebhookException('Webhook JSON payload must be a non-empty object.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseVerified(string $payload, ?string $xSignBase64, ?string $publicKeyBase64 = null): array
    {
        if (!$this->verify($payload, $xSignBase64, $publicKeyBase64)) {
            throw new MonoWebhookException('Webhook signature is invalid.');
        }

        return $this->parse($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function payload(array $payload): WebhookPayload
    {
        return WebhookPayload::fromArray($payload);
    }

    public function parsePayload(string $payload): WebhookPayload
    {
        return $this->payload($this->parse($payload));
    }

    public function parseVerifiedPayload(
        string $payload,
        ?string $xSignBase64,
        ?string $publicKeyBase64 = null
    ): WebhookPayload {
        return $this->payload($this->parseVerified($payload, $xSignBase64, $publicKeyBase64));
    }

    /**
     * Returns true only when the incoming payload is newer than the locally stored event.
     *
     * Precision follows the stored value: a raw `modifiedDate` string or an
     * Instant is compared with nanoseconds; a DateTimeInterface is compared at
     * microsecond precision exactly as in 1.x, so a redelivered event whose
     * nanoseconds were lost when the DateTime was stored is still a replay.
     *
     * `modifiedDate` is optional in the Monobank schema (for example card
     * tokenization status callbacks). Such payloads are applied by default so
     * they are not silently dropped; use `deduplicationKey()` for replay
     * protection and pass `allowMissingModifiedDate: false` to reject them.
     *
     * @param array<string, mixed> $payload
     * @param DateTimeInterface|Instant|string|null $storedModifiedDate
     */
    public function shouldApply(
        array $payload,
        $storedModifiedDate = null,
        bool $allowMissingModifiedDate = true
    ): bool {
        $incoming = $this->getModifiedInstant($payload);

        // Decide on the incoming date first: a missing date is either accepted or
        // rejected regardless of what (if anything) has been stored so far.
        if ($incoming === null) {
            return $allowMissingModifiedDate;
        }

        if ($storedModifiedDate === null) {
            return true;
        }

        if ($storedModifiedDate instanceof DateTimeInterface) {
            // 1.x semantics: both sides at microsecond precision.
            return $incoming->toDateTimeImmutable() > DateTimeImmutable::createFromInterface($storedModifiedDate);
        }

        return $incoming->isAfter(Instant::from($storedModifiedDate));
    }

    /**
     * @param DateTimeInterface|Instant|string|null $storedModifiedDate
     */
    public function shouldApplyPayload(
        WebhookPayload $payload,
        $storedModifiedDate = null,
        bool $allowMissingModifiedDate = true
    ): bool {
        return $this->shouldApply($payload->toArray(), $storedModifiedDate, $allowMissingModifiedDate);
    }

    /**
     * Full-precision `modifiedDate`, or null when the field is absent or empty.
     *
     * @param array<string, mixed> $payload
     */
    public function getModifiedInstant(array $payload): ?Instant
    {
        $value = $payload['modifiedDate'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new MonoWebhookException('Webhook modifiedDate must be an RFC3339 string.');
        }

        $instant = Rfc3339::parseInstant($value);

        if ($instant === null) {
            throw new MonoWebhookException('Webhook modifiedDate is not a valid RFC3339 timestamp: ' . $value);
        }

        return $instant;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function getModifiedDate(array $payload): ?DateTimeImmutable
    {
        return $this->getModifiedInstant($payload)?->toDateTimeImmutable();
    }
}
