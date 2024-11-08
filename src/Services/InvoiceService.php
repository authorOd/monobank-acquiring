<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Models\InvoiceData;

class InvoiceService extends AbstractService
{
    /**
     * @param array $data
     * @return mixed
     */
    public function createInvoice(InvoiceData $invoiceData)
    {
        return $this->sendRequest('POST', '/create', $invoiceData->toArray());
    }

    /**
     * @param string $invoiceId
     * @return mixed
     */
    public function getInvoiceStatus(string $invoiceId)
    {
        return $this->sendRequest('GET', "/status?invoiceId={$invoiceId}");
    }

    /**
     * @param string $invoiceId
     * @return mixed
     */
    public function cancelInvoice(string $invoiceId)
    {
        return $this->sendRequest('POST', "/cancel", ['invoiceId' => $invoiceId]);
    }
}