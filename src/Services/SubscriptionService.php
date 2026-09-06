<?php

namespace Vladchornyi\Mono\Services;

use InvalidArgumentException;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Support\Rfc3339;

class SubscriptionService extends AbstractService
{
    /**
     * @return array<string, mixed>
     */
    public function createSubscription(SubscriptionData $subscriptionData): array
    {
        return $this->sendRequest('POST', '/create', $subscriptionData->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionStatus(string $subscriptionId): array
    {
        InputValidator::nonEmptyString($subscriptionId, 'subscriptionId');

        return $this->sendRequest('GET', '/status', null, ['subscriptionId' => $subscriptionId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionPayments(
        string $subscriptionId,
        string $dateFrom,
        ?string $dateTo = null,
        int $limit = 20,
        int $page = 1
    ): array {
        InputValidator::nonEmptyString($subscriptionId, 'subscriptionId');
        $this->validateListParameters($dateFrom, $dateTo, $limit, $page);

        return $this->sendRequest('GET', '/payments', null, [
            'subscriptionId' => $subscriptionId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => $limit,
            'page' => $page,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionList(
        string $dateFrom,
        ?string $dateTo = null,
        ?string $status = null,
        int $limit = 20,
        int $page = 1
    ): array {
        $this->validateListParameters($dateFrom, $dateTo, $limit, $page);

        if ($status !== null && !in_array($status, ['active', 'cancelled'], true)) {
            throw new InvalidArgumentException('status must be active or cancelled.');
        }

        return $this->sendRequest('GET', '/list', null, [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'status' => $status,
            'limit' => $limit,
            'page' => $page,
        ]);
    }

    /**
     * Invalidate a subscription that has not been charged yet (`/subscription/remove`).
     * Monobank rejects this call once at least one payment exists; use
     * cancelSubscription() for an active subscription.
     *
     * @return array<string, mixed>
     */
    public function removeSubscription(string $subscriptionId): array
    {
        InputValidator::nonEmptyString($subscriptionId, 'subscriptionId');

        return $this->sendRequest('POST', '/remove', ['subscriptionId' => $subscriptionId]);
    }

    /**
     * Cancel a subscription (`/subscription/edit` with `action=cancel`),
     * optionally refunding `refundAmount` minor units. Works for subscriptions
     * with or without previous charges.
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $subscriptionId, ?int $refundAmount = null): array
    {
        return $this->editSubscription($subscriptionId, 'cancel', $refundAmount);
    }

    /**
     * @return array<string, mixed>
     */
    public function editSubscription(string $subscriptionId, string $action, ?int $refundAmount = null): array
    {
        InputValidator::nonEmptyString($subscriptionId, 'subscriptionId');

        if ($action !== 'cancel') {
            throw new InvalidArgumentException('action must be cancel.');
        }

        if ($refundAmount !== null) {
            InputValidator::positiveInt($refundAmount, 'refundAmount');
        }

        return $this->sendRequest('POST', '/edit', [
            'subscriptionId' => $subscriptionId,
            'action' => $action,
            'refundAmount' => $refundAmount,
        ]);
    }

    protected function validateListParameters(
        string $dateFrom,
        ?string $dateTo,
        int $limit,
        int $page
    ): void {
        InputValidator::rfc3339($dateFrom, 'dateFrom');

        if ($dateTo !== null) {
            InputValidator::rfc3339($dateTo, 'dateTo');

            $from = Rfc3339::parse($dateFrom);
            $to = Rfc3339::parse($dateTo);

            if ($from !== null && $to !== null && $to < $from) {
                throw new InvalidArgumentException('dateTo must be greater than or equal to dateFrom.');
            }
        }

        InputValidator::positiveInt($limit, 'limit');
        InputValidator::positiveInt($page, 'page');
    }
}
