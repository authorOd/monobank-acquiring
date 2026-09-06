<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Models\WalletPaymentData;
use Vladchornyi\Mono\Support\InputValidator;

/**
 * Tokenized cards (`/api/merchant/wallet`). Requires tokenization to be
 * enabled for the merchant by Monobank support.
 */
class WalletService extends AbstractService
{
    /**
     * Cards saved in a customer wallet.
     *
     * @return array<string, mixed>
     */
    public function getCards(string $walletId): array
    {
        InputValidator::nonEmptyString($walletId, 'walletId');

        return $this->sendRequest('GET', '', null, ['walletId' => $walletId]);
    }

    /**
     * Charge a saved card token (merchant- or client-initiated payment).
     *
     * @return array<string, mixed>
     */
    public function payByToken(WalletPaymentData $paymentData): array
    {
        return $this->sendRequest('POST', '/payment', $paymentData->toArray());
    }

    /**
     * Delete a tokenized card.
     *
     * @return array<string, mixed>
     */
    public function deleteCard(string $cardToken): array
    {
        InputValidator::nonEmptyString($cardToken, 'cardToken');

        return $this->sendRequest('DELETE', '/card', null, ['cardToken' => $cardToken]);
    }
}
