# Subscriptions

## Create a Subscription

```php
use Vladchornyi\Mono\Models\SubscriptionData;

$subscriptionData = new SubscriptionData(
    amount: 59000,
    ccy: 980,
    redirectUrl: 'https://example.com/subscriptions/return',
    webHookUrls: [
        'chargeUrl' => 'https://example.com/webhooks/monobank/subscription/charge',
        'statusUrl' => 'https://example.com/webhooks/monobank/subscription/status',
    ],
    interval: '1m',
    validity: 3600
);

$subscription = $mono->subscriptions()->createSubscription($subscriptionData);

echo $subscription['subscriptionId'];
echo $subscription['pageUrl'];
```

## Status

```php
$status = $mono->subscriptions()->getSubscriptionStatus($subscriptionId);
```

## Payment History

```php
$dateFrom = (new DateTimeImmutable('-1 month'))->format(DATE_RFC3339);

$payments = $mono->subscriptions()->getSubscriptionPayments(
    subscriptionId: $subscriptionId,
    dateFrom: $dateFrom,
    limit: 20,
    page: 1
);
```

## List Subscriptions

```php
$subscriptions = $mono->subscriptions()->getSubscriptionList(
    dateFrom: $dateFrom,
    status: 'active',
    limit: 20,
    page: 1
);
```

## Cancel or Edit

```php
$mono->subscriptions()->editSubscription($subscriptionId, 'cancel');
$mono->subscriptions()->cancelSubscription($subscriptionId);
```

If you process subscription charge webhooks, store each charge by `invoiceId` and
make terminal side effects idempotent.

## Useful Payload Fields

Subscription status payloads commonly expose:

- `subscriptionId`
- `status`: `created`, `pending`, `active`, `paused`, `cancelled`, `removed`
- `amount`, `ccy`, `interval`
- `startDate`, `endDate`, `nextChargeDate`
- `cancellationDesc`
- `summary.totalPaid`, `summary.totalFailed`
- `walletData.status`, `walletData.walletId`, `walletData.cardToken`

Subscription payment history responses commonly expose:

- `payments[].amount`
- `payments[].status`
- `payments[].ccy`
- `payments[].chargedAt`
- `pagination.totalItems`
- `pagination.itemsPerPage`
- `pagination.currentPage`
- `pagination.totalPages`

Use the webhook payload helper for status and charge callbacks:

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign, $cachedPublicKey);

if ($event->type() === \Vladchornyi\Mono\Webhooks\WebhookPayload::TYPE_SUBSCRIPTION_STATUS) {
    $subscriptionId = $event->subscriptionId();
    $status = $event->status();
    $totalPaid = $event->totalPaid();
}
```
