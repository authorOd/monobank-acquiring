<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;
use Vladchornyi\Mono\Support\InputValidator;

class SubscriptionData
{
    /** Maximum invoice lifetime accepted by Monobank (30 days). */
    public const MAX_VALIDITY_SECONDS = 2592000;

    protected int $amount;
    protected int $ccy;
    protected ?string $redirectUrl;
    /** @var array<string, mixed>|null */
    protected ?array $webHookUrls;
    protected string $interval;
    protected ?int $validity;

    /**
     * @param array{chargeUrl: string, statusUrl: string}|null $webHookUrls
     */
    public function __construct(
        int $amount,
        int $ccy,
        ?string $redirectUrl,
        ?array $webHookUrls,
        string $interval,
        ?int $validity = null
    ) {
        $this->amount = $amount;
        $this->ccy = $ccy;
        $this->redirectUrl = $redirectUrl;
        $this->webHookUrls = $webHookUrls;
        $this->interval = $interval;
        $this->validity = $validity;

        $this->validate();
    }

    protected function validate(): void
    {
        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be a positive integer.');
        }

        if ($this->ccy < 1 || $this->ccy > 999) {
            throw new InvalidArgumentException('ccy must be a valid ISO 4217 numeric code.');
        }

        if ($this->redirectUrl !== null) {
            InputValidator::httpUrl($this->redirectUrl, 'redirectUrl');
        }

        if ($this->webHookUrls !== null) {
            foreach (['chargeUrl', 'statusUrl'] as $name) {
                $value = $this->webHookUrls[$name] ?? null;

                if (!is_string($value)) {
                    throw new InvalidArgumentException("webHookUrls.{$name} must be a string.");
                }

                InputValidator::httpUrl($value, "webHookUrls.{$name}");
            }
        }

        if (preg_match('/^[1-9][0-9]*[dwmy]$/D', $this->interval) !== 1) {
            throw new InvalidArgumentException('Interval must use the format {positive number}{d|w|m|y}.');
        }

        if ($this->validity !== null && $this->validity <= 0) {
            throw new InvalidArgumentException('validity must be a positive integer.');
        }

        if ($this->validity !== null && $this->validity > self::MAX_VALIDITY_SECONDS) {
            // Monobank truncates longer lifetimes to 30 days; mirror that instead of failing.
            $this->validity = self::MAX_VALIDITY_SECONDS;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->validate();

        return array_filter([
            'amount' => $this->amount,
            'ccy' => $this->ccy,
            'redirectUrl' => $this->redirectUrl,
            'webHookUrls' => $this->webHookUrls,
            'interval' => $this->interval,
            'validity' => $this->validity,
        ], static fn($value): bool => $value !== null);
    }
}
