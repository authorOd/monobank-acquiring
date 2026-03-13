<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class InvoiceData
{
	protected int $amount; // Сума оплати в копійках
	protected ?string $redirectUrl; // Адреса для повернення після оплати
	protected ?string $webHookUrl; // Адреса для CallBack
	protected ?array $saveCardData; // Дані для збереження (токенізації) картки
	protected ?MerchantPaymInfoItem $merchantPaymInfo;

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
		?MerchantPaymInfoItem $merchantPaymInfo = null
	) {
		$this->amount = $amount;
		$this->redirectUrl = $redirectUrl;
		$this->webHookUrl = $webHookUrl;
		$this->saveCardData = $saveCardData;
		$this->merchantPaymInfo = $merchantPaymInfo;

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

		if ($this->redirectUrl !== null && !filter_var($this->redirectUrl, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('redirectUrl must be a valid URL.');
		}

		if ($this->webHookUrl !== null && !filter_var($this->webHookUrl, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('webHookUrl must be a valid URL.');
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
			'redirectUrl' => $this->redirectUrl,
			'webHookUrl' => $this->webHookUrl,
			'saveCardData' => $this->saveCardData,
			'merchantPaymInfo' => $this->merchantPaymInfo?->toArray(),
		], fn($value) => $value !== null);
	}
}