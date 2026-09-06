<?php

namespace Vladchornyi\Mono\Services;

use InvalidArgumentException;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Support\InputValidator;

class InvoiceService extends AbstractService
{
    /**
     * @return array<string, mixed>
     */
    public function createInvoice(InvoiceData $invoiceData): array
    {
        return $this->sendRequest('POST', '/create', $invoiceData->toArray());
    }

    /**
     * @param string $invoiceId
     * @return array<string, mixed>
     */
    public function getInvoiceStatus(string $invoiceId): array
    {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');

        return $this->sendRequest('GET', '/status', null, ['invoiceId' => $invoiceId]);
    }

    /**
     * @param string $invoiceId
     * @param array<int, array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    public function cancelInvoice(
        string $invoiceId,
        ?string $extRef = null,
        ?int $amount = null,
        ?array $items = null
    ): array {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');

        if ($extRef !== null) {
            InputValidator::nonEmptyString($extRef, 'extRef');
        }

        if ($amount !== null) {
            InputValidator::positiveInt($amount, 'amount');
        }

        return $this->sendRequest('POST', '/cancel', [
            'invoiceId' => $invoiceId,
            'extRef' => $extRef,
            'amount' => $amount,
            'items' => $this->normalizeItems($items),
        ]);
    }

    /**
     * Invalidate an unpaid invoice.
     *
     * @return array<string, mixed>
     */
    public function removeInvoice(string $invoiceId): array
    {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');

        return $this->sendRequest('POST', '/remove', ['invoiceId' => $invoiceId]);
    }

    /**
     * Backward-friendly alias for invoice invalidation.
     *
     * @return array<string, mixed>
     */
    public function invalidateInvoice(string $invoiceId): array
    {
        return $this->removeInvoice($invoiceId);
    }

    /**
     * Finalize a hold payment. `amount` is required by the Monobank schema.
     *
     * @param array<int, array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    public function finalizeHold(string $invoiceId, int $amount, ?array $items = null): array
    {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');
        InputValidator::positiveInt($amount, 'amount');

        return $this->sendRequest('POST', '/finalize', [
            'invoiceId' => $invoiceId,
            'amount' => $amount,
            'items' => $this->normalizeItems($items),
        ]);
    }

    /**
     * Fiscalization items must reach the API as a JSON list. Re-index so gaps
     * left by unset()/array_filter() cannot turn the list into a JSON object.
     *
     * @param array<int, array<string, mixed>>|null $items
     * @return array<int, array<string, mixed>>|null
     */
    protected function normalizeItems(?array $items): ?array
    {
        if ($items === null) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('items must contain fiscalization item arrays.');
            }
        }

        return array_values($items);
    }

    /**
     * Payment receipt as a base64-encoded PDF (`file`); optionally e-mails it.
     *
     * @return array<string, mixed>
     */
    public function getReceipt(string $invoiceId, ?string $email = null): array
    {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('email must be a valid e-mail address.');
        }

        return $this->sendRequest('GET', '/receipt', null, [
            'invoiceId' => $invoiceId,
            'email' => $email,
        ]);
    }

    /**
     * Fiscal checks and their statuses for an invoice.
     *
     * @return array<string, mixed>
     */
    public function getFiscalChecks(string $invoiceId): array
    {
        InputValidator::nonEmptyString($invoiceId, 'invoiceId');

        return $this->sendRequest('GET', '/fiscal-checks', null, ['invoiceId' => $invoiceId]);
    }
}
