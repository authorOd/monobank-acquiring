<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;
use Vladchornyi\Mono\Support\InputValidator;

class InvoiceData
{
    /** Maximum invoice lifetime accepted by Monobank (30 days). */
    public const MAX_VALIDITY_SECONDS = 2592000;

    protected int $amount;
    protected ?int $ccy;
    protected ?string $redirectUrl;
    protected ?string $webHookUrl;
    protected ?SaveCardData $saveCardData;
    protected ?MerchantPaymInfoItem $merchantPaymInfo;
    protected ?int $validity;
    protected ?string $paymentType;
    protected ?string $qrId;
    protected ?string $code;
    protected ?float $agentFeePercent;
    protected ?string $tipsEmployeeId;
    protected ?string $successUrl;
    protected ?string $failUrl;
    protected ?string $displayType;
    protected ?bool $withAppUrl;

    /**
     * @param array{saveCard: bool, walletId?: string}|SaveCardData|null $saveCardData
     */
    public function __construct(
        int $amount,
        ?string $redirectUrl = null,
        ?string $webHookUrl = null,
        array|SaveCardData|null $saveCardData = null,
        ?MerchantPaymInfoItem $merchantPaymInfo = null,
        ?int $ccy = null,
        ?int $validity = null,
        ?string $paymentType = null,
        ?string $qrId = null,
        ?string $code = null,
        ?float $agentFeePercent = null,
        ?string $tipsEmployeeId = null,
        ?string $successUrl = null,
        ?string $failUrl = null,
        ?string $displayType = null,
        ?bool $withAppUrl = null
    ) {
        $this->amount = $amount;
        $this->ccy = $ccy;
        $this->redirectUrl = $redirectUrl;
        $this->webHookUrl = $webHookUrl;
        $this->saveCardData = is_array($saveCardData)
            ? SaveCardData::fromArray($saveCardData)
            : $saveCardData;
        $this->merchantPaymInfo = $merchantPaymInfo;
        $this->validity = $validity;
        $this->paymentType = $paymentType;
        $this->qrId = $qrId;
        $this->code = $code;
        $this->agentFeePercent = $agentFeePercent;
        $this->tipsEmployeeId = $tipsEmployeeId;
        $this->successUrl = $successUrl;
        $this->failUrl = $failUrl;
        $this->displayType = $displayType;
        $this->withAppUrl = $withAppUrl;

        $this->validate();
    }

    protected function validate(): void
    {
        $paymentType = $this->paymentType ?? 'debit';

        if (!in_array($paymentType, ['debit', 'hold', 'verification'], true)) {
            throw new InvalidArgumentException('paymentType must be debit, hold, or verification.');
        }

        if ($paymentType === 'verification') {
            if ($this->amount !== 0) {
                throw new InvalidArgumentException('Verification invoices must have amount equal to 0.');
            }
        } elseif ($this->amount <= 0) {
            throw new InvalidArgumentException('Amount must be a positive integer.');
        }

        if ($this->ccy !== null && ($this->ccy < 1 || $this->ccy > 999)) {
            throw new InvalidArgumentException('ccy must be a valid ISO 4217 numeric code.');
        }

        foreach (
            [
                'redirectUrl' => $this->redirectUrl,
                'webHookUrl' => $this->webHookUrl,
                'successUrl' => $this->successUrl,
                'failUrl' => $this->failUrl,
            ] as $name => $url
        ) {
            if ($url !== null) {
                InputValidator::httpUrl($url, $name);
            }
        }

        if ($this->validity !== null && $this->validity <= 0) {
            throw new InvalidArgumentException('validity must be a positive integer.');
        }

        if ($this->validity !== null && $this->validity > self::MAX_VALIDITY_SECONDS) {
            // Monobank truncates longer lifetimes to 30 days; mirror that instead of failing.
            $this->validity = self::MAX_VALIDITY_SECONDS;
        }

        if ($this->agentFeePercent !== null) {
            if (!is_finite($this->agentFeePercent) || $this->agentFeePercent < 0 || $this->agentFeePercent > 100) {
                throw new InvalidArgumentException('agentFeePercent must be a finite number between 0 and 100.');
            }
        }

        if ($this->displayType !== null && $this->displayType !== 'iframe') {
            throw new InvalidArgumentException('displayType must be iframe.');
        }

        foreach (
            [
                'qrId' => $this->qrId,
                'code' => $this->code,
                'tipsEmployeeId' => $this->tipsEmployeeId,
            ] as $name => $value
        ) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException("{$name} must not be empty.");
            }
        }

        if ($paymentType === 'verification' && $this->saveCardData?->shouldSaveCard() !== true) {
            throw new InvalidArgumentException('Verification invoices require saveCardData.saveCard to be true.');
        }

        if ($this->withAppUrl === true && ($this->qrId !== null || $paymentType === 'verification')) {
            throw new InvalidArgumentException('withAppUrl is not supported for QR or verification invoices.');
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
            'successUrl' => $this->successUrl,
            'failUrl' => $this->failUrl,
            'webHookUrl' => $this->webHookUrl,
            'validity' => $this->validity,
            'paymentType' => $this->paymentType,
            'qrId' => $this->qrId,
            'code' => $this->code,
            'saveCardData' => $this->saveCardData?->toArray(),
            'merchantPaymInfo' => $this->merchantPaymInfo?->toArray(),
            'agentFeePercent' => $this->agentFeePercent,
            'tipsEmployeeId' => $this->tipsEmployeeId,
            'displayType' => $this->displayType,
            'withAppUrl' => $this->withAppUrl,
        ], static fn($value): bool => $value !== null);
    }
}
