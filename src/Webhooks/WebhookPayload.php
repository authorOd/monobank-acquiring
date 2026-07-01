<?php

namespace Vladchornyi\Mono\Webhooks;

use DateTimeImmutable;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
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

    /** @var array<string, mixed> */
    protected array $data;

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
        return $this->intOrNull('amount');
    }

    public function finalAmount(): ?int
    {
        return $this->intOrNull('finalAmount');
    }

    public function ccy(): ?int
    {
        return $this->intOrNull('ccy');
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
        return $this->intOrNull('summary.totalPaid');
    }

    public function totalFailed(): ?int
    {
        return $this->intOrNull('summary.totalFailed');
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
     * A stable local key suitable for deduplication logs.
     */
    public function eventKey(): string
    {
        if ($this->invoiceId() !== null) {
            return $this->type() . ':' . $this->invoiceId();
        }

        if ($this->subscriptionId() !== null) {
            return $this->type() . ':' . $this->subscriptionId();
        }

        $json = json_encode($this->data);

        return self::TYPE_UNKNOWN . ':' . sha1(is_string($json) ? $json : serialize($this->data));
    }

    public function isInvoiceStatus(): bool
    {
        $status = $this->status();

        return $status !== null && in_array($status, self::INVOICE_STATUSES, true);
    }

    public function isSubscriptionStatus(): bool
    {
        $status = $this->status();

        return $status !== null && in_array($status, self::SUBSCRIPTION_STATUSES, true);
    }

    public function isSuccessful(): bool
    {
        return $this->status() === 'success';
    }

    public function isFailure(): bool
    {
        return in_array($this->status(), ['failure', 'expired', 'reversed', 'failed'], true);
    }

    public function isTerminalInvoiceStatus(): bool
    {
        return in_array($this->status(), ['success', 'failure', 'reversed', 'expired'], true);
    }

    protected function stringOrNull(string $path): ?string
    {
        $value = $this->get($path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function intOrNull(string $path): ?int
    {
        $value = $this->get($path);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function dateOrNull(string $path): ?DateTimeImmutable
    {
        $value = $this->stringOrNull($path);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new MonoWebhookException("Webhook {$path} is invalid: {$value}", 0, $e);
        }
    }
}
