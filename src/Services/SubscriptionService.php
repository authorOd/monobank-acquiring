<?php

namespace Vladchornyi\Mono\Services;

use InvalidArgumentException;
use Vladchornyi\Mono\Exceptions\MonoException;
use Vladchornyi\Mono\Exceptions\MonoJsonException;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\Support\InputValidator;
use Vladchornyi\Mono\Support\Rfc3339;

class SubscriptionService extends AbstractService
{
    /** Safety stop for iterate*() when a server keeps reporting more pages. */
    private const MAX_PAGES = 1000;

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
     * Every subscription in the date range, across all pages. The first page
     * is requested immediately, so invalid arguments and API errors surface at
     * the call; later pages are requested lazily while the result is iterated.
     * Iteration stops after `pagination.totalPages`, or after the first short
     * page when the response carries no page count. A response without `list`
     * is an empty page.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function iterateSubscriptionList(
        string $dateFrom,
        ?string $dateTo = null,
        ?string $status = null,
        int $limit = 20
    ): \Generator {
        $firstPage = $this->getSubscriptionList($dateFrom, $dateTo, $status, $limit, 1);

        return $this->paginate(
            $firstPage,
            fn (int $page): array => $this->getSubscriptionList($dateFrom, $dateTo, $status, $limit, $page),
            'list',
            $limit
        );
    }

    /**
     * Every charge of a subscription in the date range, across all pages.
     * Same paging rules as iterateSubscriptionList(); items come from `payments`.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    public function iterateSubscriptionPayments(
        string $subscriptionId,
        string $dateFrom,
        ?string $dateTo = null,
        int $limit = 20
    ): \Generator {
        $firstPage = $this->getSubscriptionPayments($subscriptionId, $dateFrom, $dateTo, $limit, 1);

        return $this->paginate(
            $firstPage,
            fn (int $page): array => $this->getSubscriptionPayments($subscriptionId, $dateFrom, $dateTo, $limit, $page),
            'payments',
            $limit
        );
    }

    /**
     * @param array<array-key, mixed> $response first page
     * @param callable(int): array<array-key, mixed> $fetchPage
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    private function paginate(array $response, callable $fetchPage, string $itemsKey, int $limit): \Generator
    {
        $page = 1;

        while (true) {
            $items = $response[$itemsKey] ?? [];

            if (!is_array($items)) {
                throw new MonoJsonException("Monobank response field {$itemsKey} must be a list.");
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new MonoJsonException("Monobank response field {$itemsKey} must contain objects.");
                }

                yield $item;
            }

            $totalPages = $response['pagination']['totalPages'] ?? null;
            $hasMore = $items !== [] && (is_int($totalPages) ? $page < $totalPages : count($items) >= $limit);

            if (!$hasMore) {
                return;
            }

            if ($page >= self::MAX_PAGES) {
                throw new MonoException(sprintf(
                    'Monobank pagination did not finish after %d pages; increase limit or narrow the date range.',
                    self::MAX_PAGES
                ));
            }

            ++$page;
            $response = $fetchPage($page);
        }
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
