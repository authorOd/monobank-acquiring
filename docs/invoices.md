# Invoices

## Create an Invoice

```php
use Vladchornyi\Mono\Models\BasketOrderItem;
use Vladchornyi\Mono\Models\DiscountItem;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\MerchantPaymInfoItem;

$discount = new DiscountItem(
    type: DiscountItem::TYPE_DISCOUNT,
    mode: DiscountItem::MODE_VALUE,
    value: 1000
);

$basketItem = new BasketOrderItem(
    name: 'Online course access',
    qty: 1,
    sum: 59000,
    code: 'COURSE-ACCESS-001',
    tax: [8],
    discounts: [$discount]
);

$merchantPaymInfo = new MerchantPaymInfoItem(
    reference: 'ORDER-' . $orderId,
    destination: 'Online course access',
    comment: 'Example Store order',
    customerEmails: [$customerEmail],
    basketOrder: [$basketItem]
);

$invoiceData = new InvoiceData(
    amount: 58000,
    redirectUrl: 'https://example.com/payments/return',
    webHookUrl: 'https://example.com/webhooks/monobank/invoice',
    saveCardData: ['saveCard' => true],
    merchantPaymInfo: $merchantPaymInfo,
    ccy: 980,
    validity: 3600,
    paymentType: 'debit'
);

$invoice = $mono->invoices()->createInvoice($invoiceData);

echo $invoice['invoiceId'];
echo $invoice['pageUrl'];
```

## Supported Invoice Fields

- `amount` - amount in minor units.
- `ccy` - ISO 4217 numeric currency code, usually `980`.
- `redirectUrl` - browser redirect URL.
- `webHookUrl` - POST callback URL.
- `validity` - invoice lifetime in seconds.
- `paymentType` - `debit` or `hold`.
- `qrId` - QR cash register id.
- `code` - submerchant terminal code.
- `saveCardData` - card tokenization options.
- `merchantPaymInfo` - fiscalization/order payload.
- `agentFeePercent`.
- `tipsEmployeeId`.

## Invoice Status

```php
$status = $mono->invoices()->getInvoiceStatus($invoiceId);
```

## Cancel a Successful Payment

```php
$cancel = $mono->invoices()->cancelInvoice(
    invoiceId: $invoiceId,
    extRef: 'refund-' . $invoiceId,
    amount: 10000
);
```

## Invalidate an Unpaid Invoice

```php
$mono->invoices()->removeInvoice($invoiceId);
$mono->invoices()->invalidateInvoice($invoiceId); // alias
```

## Finalize a Hold

```php
$mono->invoices()->finalizeHold(
    invoiceId: $invoiceId,
    amount: 58000
);
```
