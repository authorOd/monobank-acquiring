# Invoices

Start with [Getting Started](getting-started.md) for the minimal invoice and
webhook. Each section below is a complete, independent example of one
additional capability and states what Monobank must enable for it.

## Minimal Invoice

```php
use Vladchornyi\Mono\Models\InvoiceData;

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    redirectUrl: 'https://example.com/payments/return',
    webHookUrl: 'https://example.com/webhooks/monobank'
));

echo $invoice['invoiceId'];
echo $invoice['pageUrl'];
```

Optional fields available to every merchant: `ccy` (default 980), `validity`
in seconds (default 24 h, clamped to 30 days), `paymentType` (`debit` by
default). All URLs must be HTTP or HTTPS.

## Invoice Status

```php
$status = $mono->invoices()->getInvoiceStatus($invoiceId);
```

Statuses: `created`, `processing`, `hold`, `success`, `failure`, `reversed`,
`expired`. Prefer webhooks; poll for `expired`, which is never delivered as a
webhook.

## Invalidate an Unpaid Invoice

```php
$mono->invoices()->removeInvoice($invoiceId);
$mono->invoices()->invalidateInvoice($invoiceId); // alias
```

## Hold and Finalize

Requires nothing extra. A hold blocks the amount for 9 days; finalize it or it
is released automatically.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$hold = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    paymentType: 'hold',
    webHookUrl: 'https://example.com/webhooks/monobank'
));

// After the `hold` webhook: capture the full amount or a smaller one.
$mono->invoices()->finalizeHold(invoiceId: $hold['invoiceId'], amount: 58000);
```

`amount` is required. When fiscalization is active and the finalized amount
differs from the original, pass `items` (same shape as `basketOrder`) so the
fiscal receipt matches.

## Refund a Successful Payment

```php
$cancel = $mono->invoices()->cancelInvoice(
    invoiceId: $invoiceId,
    extRef: 'refund-' . $invoiceId,   // your reference, optional
    amount: 10000                     // partial refund; omit for full refund
);

echo $cancel['status'];               // processing | success | failure
```

**With fiscalization enabled, `items` is mandatory**: Monobank needs the
returned goods to issue the return receipt and rejects the call otherwise.

```php
$mono->invoices()->cancelInvoice(
    invoiceId: $invoiceId,
    amount: 59000,
    items: [[
        'name' => 'Online course access',
        'qty' => 1,
        'sum' => 59000,
        'code' => 'COURSE-ACCESS-001',
    ]]
);
```

The result is asynchronous: watch `cancelList[]` in the next webhook or in
`getInvoiceStatus()`.

## Fiscalization (ПРРО)

Requires a Checkbox, Вчасно.Каса, or monopay ПРРО link configured in the
Monobank web cabinet. Once it is active, `merchantPaymInfo.basketOrder` is
mandatory on every invoice, and every item needs a `code`.

```php
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;
use Vladchornyi\Mono\Models\InvoiceData;

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    webHookUrl: 'https://example.com/webhooks/monobank',
    merchantPaymInfo: new MerchantPaymInfoItem(
        reference: 'ORDER-1001',
        destination: 'Online course access',
        customerEmails: ['buyer@example.com'],       // receipt recipients (Checkbox)
        basketOrder: [
            new BasketOrderItem(
                name: 'Online course access',
                qty: 1,
                sum: 59000,
                code: 'COURSE-ACCESS-001',
                tax: [8],                              // tax codes from your ПРРО provider
                discounts: [
                    new DiscountItem(DiscountItem::TYPE_DISCOUNT, DiscountItem::MODE_VALUE, 1000),
                ]
            ),
        ]
    )
));
```

Notes:

- `sum` is the unit price and `qty` the quantity; the SDK computes `total`.
- Discount values must be multiples of `0.01`: `1.001` is rejected, while normal
  binary float noise such as `0.1 + 0.2` is normalized to `0.3` before encoding.
- Exact duplicate `customerEmails` are removed during serialization, preserving
  the first occurrence and the original order.
- `BasketOrderItem::$tax` is a list of integer tax codes. `DiscountItem::$tax`
  is declared as an untyped array by Monobank; integer codes and tax objects are
  both accepted and sent unchanged. Use what your provider expects.
- Lists are re-indexed before encoding, so `unset()`/`array_filter()` gaps
  never turn `basketOrder` into a JSON object. Empty `metadata` is omitted.
- Fiscal receipts and their status: `getFiscalChecks()` below.

## Card Tokenization and Verification

Requires tokenization enabled by Monobank support. The `walletData` block in
the status/webhook payload then carries `cardToken` and `walletId`.

```php
use Vladchornyi\Mono\Models\SaveCardData;
use Vladchornyi\Mono\Models\InvoiceData;

// Charge and save the card.
$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    saveCardData: new SaveCardData(true, 'customer-42'),   // your wallet id, optional
    webHookUrl: 'https://example.com/webhooks/monobank'
));

// Verify a card without charging it (amount must be 0).
$verification = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 0,
    paymentType: 'verification',
    saveCardData: new SaveCardData(true, 'customer-42')
));
```

Tokenization status changes arrive on the same `webHookUrl`; those payloads may
lack `modifiedDate`, which is why `shouldApply()` accepts such events by default.

## Charge a Saved Card

Requires tokenization (above). Use it for your own billing schedule; Monobank
[subscriptions](subscriptions.md) are the managed alternative.

```php
use Vladchornyi\Mono\Models\WalletPaymentData;

$cards = $mono->wallet()->getCards('customer-42');

$payment = $mono->wallet()->payByToken(new WalletPaymentData(
    cardToken: $cards['wallet'][0]['cardToken'],
    amount: 59000,
    ccy: 980,
    initiationKind: WalletPaymentData::INITIATION_MERCHANT,   // customer not present
    webHookUrl: 'https://example.com/webhooks/monobank'
));

echo $payment['status'];      // processing | success | failure
echo $payment['tdsUrl'] ?? ''; // present when a client-initiated payment needs 3DS

$mono->wallet()->deleteCard($cardToken);
```

`initiationKind` is `merchant` for unattended charges and `client` when the
customer initiates the payment.

## Split Payments

Requires the split scheme enabled by Monobank. Each basket item names the
sub-merchant that receives its money.

```php
use Vladchornyi\Mono\Models\BasketOrderItem;

$receivers = $mono->merchant()->getSplitReceivers();   // list[].splitReceiverId

$item = new BasketOrderItem(
    name: 'Handmade mug',
    qty: 1,
    sum: 45000,
    code: 'MUG-001',
    splitReceiverId: $receivers['list'][0]['splitReceiverId']
);
```

Statement rows for split invoices include `splitReceiverList[]`.

## Marketplace Terminals and Agent Fee

Requires the marketplace/agent scheme enabled by Monobank. `code` selects the
sub-merchant terminal; `agentFeePercent` is the fee you keep.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$submerchants = $mono->merchant()->getSubmerchants();   // list[].code

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    code: $submerchants['list'][0]['code'],
    agentFeePercent: 1.5
));
```

## Tips

Requires employees configured in the Monobank cabinet. The status payload then
contains `tipsInfo`. Note: the current Monobank OpenAPI document (`v2412`) no
longer lists `tipsEmployeeId`, `tipsInfo`, or the employee list endpoint; they
were documented in `v2410`. The SDK keeps them for compatibility; confirm
availability with Monobank before relying on tips.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    tipsEmployeeId: $employeeId
));

$tips = $event->tipsInfo();   // ['employeeId' => ..., 'amount' => ...] when paid
```

## Separate Success and Failure Redirects

Requires activation by Monobank support; the default is the single `redirectUrl`.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    successUrl: 'https://example.com/payments/success',
    failUrl: 'https://example.com/payments/failed'
));
```

## iFrame Widget and App Deep Link

No activation needed.

```php
use Vladchornyi\Mono\Models\InvoiceData;

$widget = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    displayType: 'iframe'          // pageUrl becomes an embeddable widget URL
));

$button = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,
    withAppUrl: true               // response gains appUrl (monobank://...)
));
```

`withAppUrl` is not available for QR invoices or `verification`.

## Receipt and Fiscal Checks

```php
$receipt = $mono->invoices()->getReceipt($invoiceId, email: 'buyer@example.com');
file_put_contents('receipt.pdf', base64_decode($receipt['file']));

$checks = $mono->invoices()->getFiscalChecks($invoiceId);   // checks[].status: new|process|done|failed
```

Both return 404 for an unknown invoice. A receipt exists only for a paid
invoice, and fiscal checks only when a ПРРО link is active. The test
environment performs no financial authorization, so these calls are not
covered by the SDK's live tests; verify them against your own paid invoice.

## CMS Modules

If you ship a payment module, identify it so Monobank can attribute traffic:

```php
use Vladchornyi\Mono\MonoClient;

$mono = new MonoClient($_ENV['MONO_KEY'], httpOptions: [
    MonoClient::OPTION_CMS_NAME => 'ExampleCMS',
    MonoClient::OPTION_CMS_VERSION => '4.2.0',
]);
```
