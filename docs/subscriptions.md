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

The interval follows `{positive number}{unit}`, where the unit is `d`, `w`, `m`,
or `y`. Examples: `1d`, `2w`, `1m`, and `1y`. Currency accepts ISO 4217 numeric
codes. Redirect and webhook URLs can be passed as `null` when they are not needed;
when `webHookUrls` is present, both `chargeUrl` and `statusUrl` must be valid HTTP
or HTTPS URLs.

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

## Cancel, Refund, or Remove

```php
// Cancel an active subscription (POST /subscription/edit, action=cancel).
$mono->subscriptions()->cancelSubscription($subscriptionId);

// Cancel and refund 5000 minor units of the last charge.
$mono->subscriptions()->cancelSubscription($subscriptionId, refundAmount: 5000);

// Same call through the generic edit method.
$mono->subscriptions()->editSubscription($subscriptionId, 'cancel', 5000);

// Invalidate a subscription that has never been charged (POST /subscription/remove).
$mono->subscriptions()->removeSubscription($subscriptionId);
```

`removeSubscription()` is rejected by Monobank once the subscription has at least
one payment; use `cancelSubscription()` for those.

If you process subscription charge webhooks, store each charge by `invoiceId` and
make terminal side effects idempotent. To charge a saved card yourself instead of
using Monobank subscriptions, see `MonoClient::wallet()->payByToken()` in
[Invoices](invoices.md#charge-a-saved-card).

## Useful Payload Fields

Subscription status payloads commonly expose:

- `subscriptionId`
- `status`: Monobank documents `active` and `cancelled`; `created`, `pending`,
  `paused`, `removed`, `expired`, and `failed` have been observed and are listed
  in `WebhookPayload::SUBSCRIPTION_STATUSES`
- `amount`, `ccy`, `interval`
- `startDate`, `endDate`, `nextChargeDate`
- `cancellationDesc`
- `summary.totalPaid`, `summary.totalFailed`
- `walletData.status`, `walletData.walletId`, `walletData.cardToken`

Subscription payment history responses commonly expose:

- `payments[].amount`
- `payments[].status`: `new`, `success`, `failed`
- `payments[].ccy`
- `payments[].chargedAt`
- `pagination.totalItems`
- `pagination.itemsPerPage`
- `pagination.currentPage`
- `pagination.totalPages`

Use the webhook payload helper for status and charge callbacks:

`MonoClient::webhooks()` verifies out of the box; inject a shared `publicKeyCache`
on multi-worker deployments, or pass a trusted public key as the third argument
to `parseVerifiedPayload()`. See [Webhooks](webhooks.md#verify-and-parse).

```php
$event = $mono->webhooks()->parseVerifiedPayload($rawBody, $xSign);

if ($event->type() === \Vladchornyi\Mono\Webhooks\WebhookPayload::TYPE_SUBSCRIPTION_STATUS) {
    $subscriptionId = $event->subscriptionId();
    $status = $event->status();
    $totalPaid = $event->totalPaid();
}
```
