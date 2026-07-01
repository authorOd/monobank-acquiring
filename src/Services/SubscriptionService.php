<?php

namespace Vladchornyi\Mono\Services;

use Vladchornyi\Mono\Models\SubscriptionData;

class SubscriptionService extends AbstractService
{
	/**
	 * Create a subscription for recurring payments
	 *
	 * @param SubscriptionData $subscriptionData
	 * @return array<string, mixed>
	 */
	public function createSubscription(SubscriptionData $subscriptionData): array
	{
		return $this->sendRequest('POST', '/create', $subscriptionData->toArray());
	}

	/**
	 * Get subscription status
	 *
	 * @param string $subscriptionId
	 * @return array<string, mixed>
	 */
	public function getSubscriptionStatus(string $subscriptionId): array
	{
		return $this->sendRequest('GET', '/status', null, ['subscriptionId' => $subscriptionId]);
	}

	/**
	 * Get subscription payment history
	 *
	 * @param string $subscriptionId
	 * @param string $dateFrom Format: rfc3339 (2024-06-26T18:12:44+03:00)
	 * @param string|null $dateTo Format: rfc3339 (2024-06-26T18:12:44+03:00)
	 * @param int $limit Default 20
	 * @param int $page Default 1
	 * @return array<string, mixed>
	 */
	public function getSubscriptionPayments(
		string $subscriptionId,
		string $dateFrom,
		?string $dateTo = null,
		int $limit = 20,
		int $page = 1
	): array {
		$params = [
			'subscriptionId' => $subscriptionId,
			'dateFrom' => $dateFrom,
			'limit' => $limit,
			'page' => $page,
		];

		if ($dateTo !== null) {
			$params['dateTo'] = $dateTo;
		}

		return $this->sendRequest('GET', '/payments', null, $params);
	}

	/**
	 * Get list of subscriptions
	 *
	 * @param string $dateFrom Format: rfc3339 (2024-06-26T18:12:44+03:00)
	 * @param string|null $dateTo Format: rfc3339 (2024-06-26T18:12:44+03:00)
	 * @param string|null $status Status: active, cancelled
	 * @param int $limit Default 20
	 * @param int $page Default 1
	 * @return array<string, mixed>
	 */
	public function getSubscriptionList(
		string $dateFrom,
		?string $dateTo = null,
		?string $status = null,
		int $limit = 20,
		int $page = 1
	): array {
		$params = [
			'dateFrom' => $dateFrom,
			'limit' => $limit,
			'page' => $page,
		];

		if ($dateTo !== null) {
			$params['dateTo'] = $dateTo;
		}

		if ($status !== null) {
			$params['status'] = $status;
		}

		return $this->sendRequest('GET', '/list', null, $params);
	}

	/**
	 * Cancel/remove subscription
	 *
	 * @param string $subscriptionId
	 * @return array<string, mixed>
	 */
	public function cancelSubscription(string $subscriptionId): array
	{
		return $this->sendRequest('POST', '/remove', ['subscriptionId' => $subscriptionId]);
	}

	/**
	 * Edit subscription (cancel or refund)
	 *
	 * @param string $subscriptionId
	 * @param string $action Action: cancel
	 * @param int|null $refundAmount Amount to refund in kopiykas
	 * @return array<string, mixed>
	 */
	public function editSubscription(string $subscriptionId, string $action, ?int $refundAmount = null): array
	{
		$data = [
			'subscriptionId' => $subscriptionId,
			'action' => $action,
		];

		if ($refundAmount !== null) {
			$data['refundAmount'] = $refundAmount;
		}

		return $this->sendRequest('POST', '/edit', $data);
	}
}
