<?php

namespace Vladchornyi\Mono\Models;

use http\Exception\InvalidArgumentException;

class InvoiceData
{
    protected int $amount; // Сума оплати в копійках
    protected ?string $redirectUrl; // Адреса для повернення після оплати
    protected ?string $webHookUrl; // Адреса для CallBack
    protected ?array $saveCardData; // Дані для збереження (токенізації) картки

    /**
     * @param int $amount
     * @param string|null $redirectUrl
     * @param string|null $webHookUrl
     * @param array|null $saveCardData
     */
    public function __construct(
        int $amount,
        ?string $redirectUrl = null,
        ?string $webHookUrl = null,
        ?array $saveCardData = null
    ) {
        $this->amount = $amount;
        $this->redirectUrl = $redirectUrl;
        $this->webHookUrl = $webHookUrl;
        $this->saveCardData = $saveCardData;

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
    }

    /**
     * Convert the data to an associative array for API request
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'redirectUrl' => $this->redirectUrl,
            'webHookUrl' => $this->webHookUrl,
            'saveCardData' => $this->saveCardData,
        ];
    }
}