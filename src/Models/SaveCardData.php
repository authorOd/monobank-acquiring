<?php

namespace Vladchornyi\Mono\Models;

use InvalidArgumentException;

final class SaveCardData
{
    private bool $saveCard;
    private ?string $walletId;

    public function __construct(bool $saveCard, ?string $walletId = null)
    {
        if ($walletId !== null && trim($walletId) === '') {
            throw new InvalidArgumentException('walletId must be a non-empty string when provided.');
        }

        $this->saveCard = $saveCard;
        $this->walletId = $walletId;
    }

    /**
     * Unknown keys are ignored so 1.x array payloads keep working; only the
     * documented `saveCard` and `walletId` fields are sent to Monobank.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!array_key_exists('saveCard', $data) || !is_bool($data['saveCard'])) {
            throw new InvalidArgumentException('saveCardData.saveCard is required and must be boolean.');
        }

        $walletId = null;
        if (array_key_exists('walletId', $data)) {
            if (!is_string($data['walletId']) || trim($data['walletId']) === '') {
                throw new InvalidArgumentException('saveCardData.walletId must be a non-empty string.');
            }

            $walletId = $data['walletId'];
        }

        return new self($data['saveCard'], $walletId);
    }

    public function shouldSaveCard(): bool
    {
        return $this->saveCard;
    }

    public function walletId(): ?string
    {
        return $this->walletId;
    }

    /**
     * @return array{saveCard: bool, walletId?: string}
     */
    public function toArray(): array
    {
        $data = ['saveCard' => $this->saveCard];

        if ($this->walletId !== null) {
            $data['walletId'] = $this->walletId;
        }

        return $data;
    }
}
