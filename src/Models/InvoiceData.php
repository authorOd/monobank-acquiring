<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class InvoiceData
{
	protected int $amount; // Сума оплати в копійках
	protected ?int $ccy; // ISO 4217, 980 UAH by default on Monobank side
	protected ?string $redirectUrl; // Адреса для повернення після оплати
	protected ?string $webHookUrl; // Адреса для CallBack
	protected ?array $saveCardData; // Дані для збереження (токенізації) картки
	protected ?MerchantPaymInfoItem $merchantPaymInfo;
	protected ?int $validity;
	protected ?string $paymentType;
	protected ?string $qrId;
	protected ?string $code;
	protected ?float $agentFeePercent;
	protected ?string $tipsEmployeeId;

	/**
	 * @param int $amount
	 * @param string|null $redirectUrl
	 * @param string|null $webHookUrl
	 * @param array|null $saveCardData
	 * @param MerchantPaymInfoItem|null $merchantPaymInfo
	 */
	public function __construct(
		int $amount,
		?string $redirectUrl = null,
		?string $webHookUrl = null,
		?array $saveCardData = null,
		?MerchantPaymInfoItem $merchantPaymInfo = null,
		?int $ccy = null,
		?int $validity = null,
		?string $paymentType = null,
		?string $qrId = null,
		?string $code = null,
		?float $agentFeePercent = null,
		?string $tipsEmployeeId = null
	) {
		$this->amount = $amount;
		$this->ccy = $ccy;
		$this->redirectUrl = $redirectUrl;
		$this->webHookUrl = $webHookUrl;
		$this->saveCardData = $saveCardData;
		$this->merchantPaymInfo = $merchantPaymInfo;
		$this->validity = $validity;
		$this->paymentType = $paymentType;
		$this->qrId = $qrId;
		$this->code = $code;
		$this->agentFeePercent = $agentFeePercent;
		$this->tipsEmployeeId = $tipsEmployeeId;

		$this->validate();
	}

	/**
	 * Validate the invoice data
	 *
	 * @throws InvalidArgumentException
	 */
	protected function validate(): void
	{
		if ($this->amount <= 0) {
			throw new InvalidArgumentException('Amount must be a positive integer.');
		}

		if ($this->ccy !== null && ($this->ccy < 1 || $this->ccy > 999)) {
			throw new InvalidArgumentException('ccy must be a valid ISO 4217 numeric code.');
		}

		if ($this->redirectUrl !== null && !filter_var($this->redirectUrl, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('redirectUrl must be a valid URL.');
		}

		if ($this->webHookUrl !== null && !filter_var($this->webHookUrl, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('webHookUrl must be a valid URL.');
		}

		if ($this->validity !== null && $this->validity <= 0) {
			throw new InvalidArgumentException('validity must be a positive integer.');
		}

		if ($this->paymentType !== null && !in_array($this->paymentType, ['debit', 'hold'], true)) {
			throw new InvalidArgumentException('paymentType must be debit or hold.');
		}

		if ($this->agentFeePercent !== null && ($this->agentFeePercent < 0 || $this->agentFeePercent > 100)) {
			throw new InvalidArgumentException('agentFeePercent must be between 0 and 100.');
		}

		if ($this->saveCardData !== null) {
			if (isset($this->saveCardData['saveCard']) && !is_bool($this->saveCardData['saveCard'])) {
				throw new InvalidArgumentException('saveCardData.saveCard must be boolean.');
			}

			if (isset($this->saveCardData['walletId']) && !is_string($this->saveCardData['walletId'])) {
				throw new InvalidArgumentException('saveCardData.walletId must be string.');
			}
		}
	}

	/**
	 * Convert the data to an associative array for API request
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return array_filter([
			'amount' => $this->amount,
			'ccy' => $this->ccy,
			'redirectUrl' => $this->redirectUrl,
			'webHookUrl' => $this->webHookUrl,
			'validity' => $this->validity,
			'paymentType' => $this->paymentType,
			'qrId' => $this->qrId,
			'code' => $this->code,
			'saveCardData' => $this->saveCardData,
			'merchantPaymInfo' => $this->merchantPaymInfo?->toArray(),
			'agentFeePercent' => $this->agentFeePercent,
			'tipsEmployeeId' => $this->tipsEmployeeId,
		], fn($value) => $value !== null);
	}
}
