<?php

namespace Vladchornyi\Mono\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Webhooks\WebhookPayload;
use Vladchornyi\Mono\Webhooks\WebhookVerifier;

class WebhookService
{
    protected PubkeyService $pubkeyService;
    protected WebhookVerifier $verifier;

    public function __construct(PubkeyService $pubkeyService, ?WebhookVerifier $verifier = null)
    {
        $this->pubkeyService = $pubkeyService;
        $this->verifier = $verifier ?? new WebhookVerifier();
    }

    public function getPublicKey(): string
    {
        $response = $this->pubkeyService->get();
        $key = $response['key'] ?? null;

        if (!is_string($key) || $key === '') {
            throw new MonoWebhookException('Monobank public key response does not contain a key');
        }

        return $key;
    }

    public function verify(string $payload, ?string $xSignBase64, ?string $publicKeyBase64 = null): bool
    {
        if ($xSignBase64 === null || $xSignBase64 === '') {
            throw new MonoWebhookException('Webhook signature is missing');
        }

        return $this->verifier->verify($payload, $xSignBase64, $publicKeyBase64 ?? $this->getPublicKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MonoJsonException('Failed to decode webhook JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new MonoJsonException('Webhook JSON payload must be an object');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseVerified(string $payload, ?string $xSignBase64, ?string $publicKeyBase64 = null): array
    {
        if (!$this->verify($payload, $xSignBase64, $publicKeyBase64)) {
            throw new MonoWebhookException('Webhook signature is invalid');
        }

        return $this->parse($payload);
    }

    public function payload(array $payload): WebhookPayload
    {
        return WebhookPayload::fromArray($payload);
    }

    public function parsePayload(string $payload): WebhookPayload
    {
        return $this->payload($this->parse($payload));
    }

    public function parseVerifiedPayload(string $payload, ?string $xSignBase64, ?string $publicKeyBase64 = null): WebhookPayload
    {
        return $this->payload($this->parseVerified($payload, $xSignBase64, $publicKeyBase64));
    }

    /**
     * Returns true when the payload is newer than the locally stored event.
     *
     * @param array<string, mixed> $payload
     */
    public function shouldApply(array $payload, ?DateTimeInterface $storedModifiedDate = null): bool
    {
        $modifiedDate = $this->getModifiedDate($payload);

        if ($modifiedDate === null || $storedModifiedDate === null) {
            return true;
        }

        return $modifiedDate > DateTimeImmutable::createFromInterface($storedModifiedDate);
    }

    public function shouldApplyPayload(WebhookPayload $payload, ?DateTimeInterface $storedModifiedDate = null): bool
    {
        return $this->shouldApply($payload->toArray(), $storedModifiedDate);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function getModifiedDate(array $payload): ?DateTimeImmutable
    {
        $value = $payload['modifiedDate'] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new MonoWebhookException('Webhook modifiedDate is invalid: ' . $value, 0, $e);
        }
    }
}
