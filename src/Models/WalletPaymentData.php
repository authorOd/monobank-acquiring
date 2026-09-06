<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;
use Vladchornyi\Mono\Support\InputValidator;

/**
 * Request body for POST /api/merchant/wallet/payment (payment by card token).
 */
class WalletPaymentData
{
    public const INITIATION_MERCHANT = 'merchant';
    public const INITIATION_CLIENT = 'client';

    protected string $cardToken;
    protected int $amount;
    protected int $ccy;
    protected string $initiationKind;
    protected ?string $redirectUrl;
    protected ?string $webHookUrl;
    protected ?MerchantPaymInfoItem $merchantPaymInfo;
    protected ?string $paymentType;

    public function __construct(
        string $cardToken,
        int $amount,
        int $ccy,
        string $initiationKind,
        ?string $redirectUrl = null,
        ?string $webHookUrl = null,
        ?MerchantPaymInfoItem $merchantPaymInfo = null,
        ?string $paymentType = null
    ) {
        $this->cardToken = $cardToken;
        $this->amount = $amount;
        $this->ccy = $ccy;
        $this->initiationKind = $initiationKind;
        $this->redirectUrl = $redirectUrl;
        $this->webHookUrl = $webHookUrl;
        $this->merchantPaymInfo = $merchantPaymInfo;
        $this->paymentType = $paymentType;

        $this->validate();
    }

    protected function validate(): void
    {
        InputValidator::nonEmptyString($this->cardToken, 'cardToken');
        InputValidator::positiveInt($this->amount, 'amount');

        if ($this->ccy < 1 || $this->ccy > 999) {
            throw new InvalidArgumentException('ccy must be a valid ISO 4217 numeric code.');
        }

        if (!in_array($this->initiationKind, [self::INITIATION_MERCHANT, self::INITIATION_CLIENT], true)) {
            throw new InvalidArgumentException('initiationKind must be merchant or client.');
        }

        foreach (['redirectUrl' => $this->redirectUrl, 'webHookUrl' => $this->webHookUrl] as $name => $url) {
            if ($url !== null) {
                InputValidator::httpUrl($url, $name);
            }
        }

        if ($this->paymentType !== null && !in_array($this->paymentType, ['debit', 'hold'], true)) {
            throw new InvalidArgumentException('paymentType must be debit or hold.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $this->validate();

        return array_filter([
            'cardToken' => $this->cardToken,
            'amount' => $this->amount,
            'ccy' => $this->ccy,
            'initiationKind' => $this->initiationKind,
            'redirectUrl' => $this->redirectUrl,
            'webHookUrl' => $this->webHookUrl,
            'merchantPaymInfo' => $this->merchantPaymInfo?->toArray(),
            'paymentType' => $this->paymentType,
        ], static fn($value): bool => $value !== null);
    }
}
