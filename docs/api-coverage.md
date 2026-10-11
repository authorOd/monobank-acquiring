# API Coverage

Status against the Monobank acquiring OpenAPI specification `v2412`
(32 endpoints). "Partial" means the endpoint is callable but the SDK adds no
typed model or validation beyond what the API requires.

## Implemented

| Endpoint | SDK |
| --- | --- |
| `POST /api/merchant/invoice/create` | `invoices()->createInvoice(InvoiceData)` |
| `GET /api/merchant/invoice/status` | `invoices()->getInvoiceStatus()` |
| `POST /api/merchant/invoice/cancel` | `invoices()->cancelInvoice()` |
| `POST /api/merchant/invoice/remove` | `invoices()->removeInvoice()` / `invalidateInvoice()` |
| `POST /api/merchant/invoice/finalize` | `invoices()->finalizeHold()` |
| `GET /api/merchant/invoice/receipt` | `invoices()->getReceipt()` |
| `GET /api/merchant/invoice/fiscal-checks` | `invoices()->getFiscalChecks()` |
| `GET /api/merchant/details` | `merchant()->getDetails()` |
| `GET /api/merchant/submerchant/list` | `merchant()->getSubmerchants()` |
| `GET /api/merchant/split-receiver/list` | `merchant()->getSplitReceivers()` |
| `GET /api/merchant/statement` | `statements()->getStatement()` |
| `GET /api/merchant/pubkey` | `pubkey()->get()`, used by `webhooks()` |
| `GET /api/merchant/wallet` | `wallet()->getCards()` |
| `POST /api/merchant/wallet/payment` | `wallet()->payByToken(WalletPaymentData)` |
| `DELETE /api/merchant/wallet/card` | `wallet()->deleteCard()` |
| `POST /api/merchant/subscription/create` | `subscriptions()->createSubscription(SubscriptionData)` |
| `GET /api/merchant/subscription/status` | `subscriptions()->getSubscriptionStatus()` |
| `GET /api/merchant/subscription/payments` | `subscriptions()->getSubscriptionPayments()` / `iterateSubscriptionPayments()` |
| `GET /api/merchant/subscription/list` | `subscriptions()->getSubscriptionList()` / `iterateSubscriptionList()` |
| `POST /api/merchant/subscription/remove` | `subscriptions()->removeSubscription()` |
| `POST /api/merchant/subscription/edit` | `subscriptions()->editSubscription()` / `cancelSubscription()` |
| Webhook signature verification (`X-Sign`) | `webhooks()->parseVerified()` and friends |

## Partial

| Area | What is missing |
| --- | --- |
| `cancelInvoice()` / `finalizeHold()` `items` | Accepted as raw arrays (re-indexed, not validated); no `FiscalizationItem` model. |
| Subscription webhooks (`chargeUrl` / `statusUrl`) | Payload shapes are not published by Monobank; `WebhookPayload` helpers are based on observed callbacks. |
| `DiscountItem::$tax` | Item type is untyped in the schema; integers and objects are passed through. |
| `InvoiceData::$tipsEmployeeId`, `WebhookPayload::tipsInfo()` | Kept from 1.x and the `v2410` document; `v2412` documents neither the request field, nor `tipsInfo`, nor the employee list endpoint. Sent/read as-is; excluded from the contract test. |
| Response models | All responses are returned as arrays; there are no typed response objects. |

## Not Implemented

| Endpoint | Area |
| --- | --- |
| `GET /api/merchant/qr/details`, `POST /api/merchant/qr/reset-amount`, `GET /api/merchant/qr/list` | QR cash registers (`qrId` on invoice creation is supported) |
| `POST /api/merchant/invoice/payment-direct` | Direct card-data payments (PCI DSS scope) |
| `POST /api/merchant/invoice/sync-payment` | Synchronous payments |
| `POST /api/merchant/pos-transaction-cancel` | POS terminals |
| `GET /api/merchant/t2p/terminal/list`, `GET /api/merchant/t2p/terminal/payment/external/status` | Tap to Phone terminals |
| `POST /api/merchant/monopay/pubkey-import`, `POST /api/merchant/monopay/pubkey-delete`, `GET /api/merchant/monopay/pubkey-list` | monopay button signing keys |
| `X-Cms` / `X-Cms-Version` | Supported via `httpOptions` (`cms_name`, `cms_version`); listed here because it is a header, not an endpoint |

Any of these can be called today through the transport with
`MonoClient::merchant()`-style services by subclassing `AbstractService`, or by
using `HttpClientInterface` directly with the `X-Token` header.
