<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Models\InvoiceData;

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
        return $this->sendRequest('GET', '/status', null, ['invoiceId' => $invoiceId]);
    }

    /**
     * @param string $invoiceId
     * @return array<string, mixed>
     */
    public function cancelInvoice(string $invoiceId, ?string $extRef = null, ?int $amount = null, ?array $items = null): array
    {
        return $this->sendRequest('POST', '/cancel', [
            'invoiceId' => $invoiceId,
            'extRef' => $extRef,
            'amount' => $amount,
            'items' => $items,
        ]);
    }

    /**
     * Invalidate an unpaid invoice.
     *
     * @return array<string, mixed>
     */
    public function removeInvoice(string $invoiceId): array
    {
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
     * Finalize a hold payment.
     *
     * @param array<int, array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    public function finalizeHold(string $invoiceId, int $amount, ?array $items = null): array
    {
        return $this->sendRequest('POST', '/finalize', [
            'invoiceId' => $invoiceId,
            'amount' => $amount,
            'items' => $items,
        ]);
    }
}
