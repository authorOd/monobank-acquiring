<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

class SubscriptionData
{
	protected int $amount; // Сума підписки в копійках
	protected int $ccy; // Код валюти (980 - гривня, 840 - долар)
	protected string $redirectUrl; // Адреса для повернення після оплати
	protected array $webHookUrls; // URL для webhook: chargeUrl, statusUrl
	protected string $interval; // Інтервал підписки (1m, 3m, 6m, 1y)
	protected ?int $validity; // Час життя посилання в секундах

	/**
	 * @param int $amount
	 * @param int $ccy
	 * @param string $redirectUrl
	 * @param array $webHookUrls ['chargeUrl' => '...', 'statusUrl' => '...']
	 * @param string $interval
	 * @param int|null $validity
	 */
	public function __construct(
		int $amount,
		int $ccy,
		string $redirectUrl,
		array $webHookUrls,
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

	/**
	 * Validate the subscription data
	 *
	 * @throws InvalidArgumentException
	 */
	protected function validate(): void
	{
		if ($this->amount <= 0) {
			throw new InvalidArgumentException('Amount must be a positive integer.');
		}

		if (!in_array($this->ccy, [980, 840])) {
			throw new InvalidArgumentException('Currency code must be 980 (UAH) or 840 (USD).');
		}

		if (!filter_var($this->redirectUrl, FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('redirectUrl must be a valid URL.');
		}

		if (!isset($this->webHookUrls['chargeUrl']) || !filter_var($this->webHookUrls['chargeUrl'], FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('webHookUrls.chargeUrl must be a valid URL.');
		}

		if (!isset($this->webHookUrls['statusUrl']) || !filter_var($this->webHookUrls['statusUrl'], FILTER_VALIDATE_URL)) {
			throw new InvalidArgumentException('webHookUrls.statusUrl must be a valid URL.');
		}

		$allowedIntervals = ['1m', '3m', '6m', '1y'];
		if (!in_array($this->interval, $allowedIntervals)) {
			throw new InvalidArgumentException('Interval must be one of: ' . implode(', ', $allowedIntervals));
		}

		if ($this->validity !== null && $this->validity <= 0) {
			throw new InvalidArgumentException('Validity must be a positive integer.');
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
			'webHookUrls' => $this->webHookUrls,
			'interval' => $this->interval,
			'validity' => $this->validity,
		], fn($value) => $value !== null);
	}
}
