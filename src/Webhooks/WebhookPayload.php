<?php

namespace Vladchornyi\Mono\Webhooks;

use DateTimeImmutable;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\Support\Instant;
use Vladchornyi\Mono\Support\Rfc3339;
use Vladchornyi\Mono\Support\SensitiveData;

class WebhookPayload
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_SUBSCRIPTION_STATUS = 'subscription_status';
    public const TYPE_SUBSCRIPTION_CHARGE = 'subscription_charge';
    public const TYPE_UNKNOWN = 'unknown';

    public const INVOICE_STATUSES = [
        'created',
        'processing',
        'hold',
        'success',
        'failure',
        'reversed',
        'expired',
    ];

    /**
     * Subscription statuses documented by Monobank are only `active` and
     * `cancelled`; the remaining values have been observed in callbacks and
     * are kept for compatibility. Treat the list as non-exhaustive.
     */
    public const SUBSCRIPTION_STATUSES = [
        'created',
        'pending',
        'active',
        'paused',
        'cancelled',
        'removed',
        'expired',
        'failed',
    ];

    /** Statuses of individual subscription charges (`/subscription/payments`). */
    public const SUBSCRIPTION_PAYMENT_STATUSES = [
        'new',
        'success',
        'failed',
    ];

    /** @var array<string, mixed> */
    protected array $data;
    private ?string $deduplicationKey = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function type(): string
    {
        if ($this->subscriptionId() !== null && $this->invoiceId() !== null) {
            return self::TYPE_SUBSCRIPTION_CHARGE;
        }

        if ($this->subscriptionId() !== null) {
            return self::TYPE_SUBSCRIPTION_STATUS;
        }

        if ($this->invoiceId() !== null) {
            return self::TYPE_INVOICE;
        }

        return self::TYPE_UNKNOWN;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function safeArray(): array
    {
        return SensitiveData::sanitize($this->data);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $path, $default = null)
    {
        $value = $this->data;

        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    public function invoiceId(): ?string
    {
        return $this->stringOrNull('invoiceId');
    }

    public function subscriptionId(): ?string
    {
        return $this->stringOrNull('subscriptionId');
    }

    public function status(): ?string
    {
        return $this->stringOrNull('status');
    }

    public function amount(): ?int
    {
        return $this->nonNegativeIntOrNull('amount');
    }

    public function finalAmount(): ?int
    {
        return $this->nonNegativeIntOrNull('finalAmount');
    }

    public function ccy(): ?int
    {
        $value = $this->intOrNull('ccy');

        return $value !== null && $value >= 1 && $value <= 999 ? $value : null;
    }

    public function interval(): ?string
    {
        return $this->stringOrNull('interval');
    }

    public function payMethod(): ?string
    {
        return $this->stringOrNull('payMethod');
    }

    public function failureReason(): ?string
    {
        return $this->stringOrNull('failureReason');
    }

    /**
     * Monobank error code for a failed payment (see "Помилки в процесі оплати").
     */
    public function errCode(): ?string
    {
        return $this->stringOrNull('errCode');
    }

    /**
     * Tips data; present when `tipsEmployeeId` was set on the invoice.
     *
     * @return array<string, mixed>
     */
    public function tipsInfo(): array
    {
        $tipsInfo = $this->get('tipsInfo', []);

        return is_array($tipsInfo) ? $tipsInfo : [];
    }

    public function cancellationDesc(): ?string
    {
        return $this->stringOrNull('cancellationDesc');
    }

    public function createdDate(): ?DateTimeImmutable
    {
        return $this->dateOrNull('createdDate');
    }

    public function modifiedDate(): ?DateTimeImmutable
    {
        return $this->dateOrNull('modifiedDate');
    }

    /**
     * `modifiedDate` with nanosecond precision for exact event ordering.
     * Store `modifiedInstant()?->toRfc3339()` (or the raw string) and pass it
     * to WebhookService::shouldApply().
     */
    public function modifiedInstant(): ?Instant
    {
        $value = $this->stringOrNull('modifiedDate');

        if ($value === null) {
            return null;
        }

        $instant = Rfc3339::parseInstant($value);

        if ($instant === null) {
            throw new MonoWebhookException("Webhook modifiedDate is not a valid RFC3339 timestamp: {$value}");
        }

        return $instant;
    }

    public function startDate(): ?DateTimeImmutable
    {
        return $this->dateOrNull('startDate');
    }

    public function endDate(): ?DateTimeImmutable
    {
        return $this->dateOrNull('endDate');
    }

    public function nextChargeDate(): ?DateTimeImmutable
    {
        return $this->dateOrNull('nextChargeDate');
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $summary = $this->get('summary', []);

        return is_array($summary) ? $summary : [];
    }

    public function totalPaid(): ?int
    {
        return $this->nonNegativeIntOrNull('summary.totalPaid');
    }

    public function totalFailed(): ?int
    {
        return $this->nonNegativeIntOrNull('summary.totalFailed');
    }

    /**
     * @return array<string, mixed>
     */
    public function walletData(): array
    {
        $walletData = $this->get('walletData', []);

        return is_array($walletData) ? $walletData : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentInfo(): array
    {
        $paymentInfo = $this->get('paymentInfo', []);

        return is_array($paymentInfo) ? $paymentInfo : [];
    }

    /**
     * 1.x compatibility alias for entityKey().
     *
     * @deprecated since 2.0, will be removed in 3.0. The value only identifies
     *             the invoice/subscription; use deduplicationKey() for replay
     *             protection.
     */
    public function eventKey(): string
    {
        @trigger_error(
            'WebhookPayload::eventKey() is deprecated since 2.0; use entityKey() for lookup '
            . 'and deduplicationKey() for replay protection.',
            E_USER_DEPRECATED
        );

        return $this->entityKey();
    }

    /**
     * A stable key for the invoice or subscription that emitted the event.
     */
    public function entityKey(): string
    {
        if ($this->invoiceId() !== null) {
            return $this->type() . ':' . $this->invoiceId();
        }

        if ($this->subscriptionId() !== null) {
            return $this->type() . ':' . $this->subscriptionId();
        }

        return self::TYPE_UNKNOWN;
    }

    /**
     * A stable key that changes when the canonical event payload changes.
     */
    public function deduplicationKey(): string
    {
        if ($this->deduplicationKey !== null) {
            return $this->deduplicationKey;
        }

        $canonical = self::canonicalize($this->data);
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        $serialized = is_string($json) ? $json : serialize($canonical);

        $this->deduplicationKey = 'evt:v2:' . hash('sha256', $this->entityKey() . "\n" . $serialized);

        return $this->deduplicationKey;
    }

    /**
     * True for invoice and subscription-charge events carrying a known status.
     * Charge callbacks may use either the invoice vocabulary or the
     * subscription payment vocabulary (`new`, `success`, `failed`).
     */
    public function isInvoiceStatus(): bool
    {
        $status = $this->status();

        if ($status === null) {
            return false;
        }

        if ($this->type() === self::TYPE_INVOICE) {
            return in_array($status, self::INVOICE_STATUSES, true);
        }

        return $this->type() === self::TYPE_SUBSCRIPTION_CHARGE
            && (
                in_array($status, self::INVOICE_STATUSES, true)
                || in_array($status, self::SUBSCRIPTION_PAYMENT_STATUSES, true)
            );
    }

    /**
     * True for subscription status events. The status vocabulary is not fully
     * documented, so any non-empty status is accepted; compare against
     * SUBSCRIPTION_STATUSES yourself when you need a strict check.
     */
    public function isSubscriptionStatus(): bool
    {
        return $this->type() === self::TYPE_SUBSCRIPTION_STATUS && $this->status() !== null;
    }

    public function isSuccessful(): bool
    {
        return $this->isInvoiceStatus() && $this->status() === 'success';
    }

    public function isFailure(): bool
    {
        if ($this->isInvoiceStatus()) {
            return in_array($this->status(), ['failure', 'failed', 'expired', 'reversed'], true);
        }

        return $this->isSubscriptionStatus()
            && in_array($this->status(), ['failed', 'expired'], true);
    }

    public function isTerminalInvoiceStatus(): bool
    {
        return in_array($this->type(), [self::TYPE_INVOICE, self::TYPE_SUBSCRIPTION_CHARGE], true)
            && in_array($this->status(), ['success', 'failure', 'failed', 'reversed', 'expired'], true);
    }

    protected function stringOrNull(string $path): ?string
    {
        $value = $this->get($path);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    protected function intOrNull(string $path): ?int
    {
        $value = $this->get($path);

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($validated) ? $validated : null;
    }

    protected function dateOrNull(string $path): ?DateTimeImmutable
    {
        $value = $this->stringOrNull($path);

        if ($value === null) {
            return null;
        }

        $date = Rfc3339::parse($value);

        if ($date === null) {
            throw new MonoWebhookException("Webhook {$path} is not a valid RFC3339 timestamp: {$value}");
        }

        return $date;
    }

    protected function nonNegativeIntOrNull(string $path): ?int
    {
        $value = $this->intOrNull($path);

        return $value !== null && $value >= 0 ? $value : null;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!self::isList($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    protected static function isList(array $value): bool
    {
        $expected = 0;

        foreach ($value as $key => $_item) {
            if ($key !== $expected) {
                return false;
            }

            ++$expected;
        }

        return true;
    }
}
