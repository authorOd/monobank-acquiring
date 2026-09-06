# Monobank Acquiring PHP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![Total Downloads](https://img.shields.io/packagist/dt/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![PHP Version](https://img.shields.io/packagist/php-v/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![License](https://img.shields.io/packagist/l/vladchornyi/mono.svg)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/authorOd/monobank-acquiring?display_name=tag)](https://github.com/authorOd/monobank-acquiring/releases)

PHP client for the Monobank Acquiring API: invoices, subscriptions, statements,
merchant details, webhook signature verification, and structured error handling.

## Features

- Invoices: create, status, cancel, invalidate, hold finalization, receipts, and
  fiscal checks.
- Subscriptions: create, status, list, payment history, cancel with refund, remove.
- Tokenized cards: wallet listing, payment by card token, card deletion.
- Statements, merchant details, submerchants, and split receivers.
- Verified webhook parsing with `X-Sign` support.
- Typed webhook payload helpers for invoice and subscription events.
- Versioned webhook deduplication and stale-event protection via `modifiedDate`.
- Rotation-aware public-key verification with injectable shared caching.
- Replaceable HTTP layer for tests and framework integrations.
- Response-header capture and safe retry handling for idempotent requests.
- Structured exceptions and safe logging helpers.

Version 2.0 contains intentional breaking changes. Existing 1.x applications
should follow the [upgrade guide](UPGRADING.md) before changing the constraint.

## Requirements

- PHP 8.0+
- `ext-json`
- `ext-mbstring`
- `ext-curl` when using the default `CurlHttpClient`.
- `ext-openssl` when using built-in webhook verification.

## Installation

```bash
composer require vladchornyi/mono:^2.0
```

## Quick Start

```php
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\MonoClient;

$mono = new MonoClient($_ENV['MONO_KEY']);

// 1. Create the invoice and send the customer to pageUrl.
$invoice = $mono->invoices()->createInvoice(new InvoiceData(
    amount: 58000,                                       // kopecks
    redirectUrl: 'https://example.com/payments/return',
    webHookUrl: 'https://example.com/webhooks/monobank'
));
header('Location: ' . $invoice['pageUrl']);

// 2. In the webhook endpoint: verify the signature, then apply the status.
$event = $mono->webhooks()->parseVerifiedPayload(
    file_get_contents('php://input'),
    $_SERVER['HTTP_X_SIGN'] ?? null
);

if ($event->isSuccessful()) {
    // mark the order paid, once per $event->deduplicationKey()
}
```

The complete walk-through, including stale-event and duplicate handling, is in
[Getting Started](docs/getting-started.md).

## Documentation

- [Getting started: first payment](docs/getting-started.md)
- [Installation and client setup](docs/installation.md)
- [Invoices](docs/invoices.md), one complete example per capability
- [API coverage](docs/api-coverage.md)
- [Webhooks](docs/webhooks.md) and the transactional handler example in
  [examples/Webhooks](examples/Webhooks/)
- [Subscriptions](docs/subscriptions.md)
- [Statements and merchant details](docs/statements.md)
- [Errors and safe logging](docs/errors-and-logging.md)
- [Upgrade notes](UPGRADING.md)

## Testing From Source

```bash
composer install
composer test
composer test:ci
composer analyse
composer cs
```

The default test suite is self-contained and does not call Monobank. It
includes four release-gate suites, also runnable alone with
`composer test:release`:

- `tests/Contract`: every SDK request is checked against the Monobank OpenAPI
  document stored in `tests/fixtures/monobank` (method, path, documented
  fields, JSON lists, required fields, query parameters); responses built from
  the specification's examples must be readable by the SDK.
- `tests/Migration`: real 1.x call forms executed against 2.0, the evidence
  behind `UPGRADING.md`.
- `tests/Regression`: one reproduction per defect found during the 2.0 review.
- `tests/Docs`: every PHP block in the docs compiles, every documented client
  call exists, and `docs/api-coverage.md` lists every specification endpoint.

For an optional live smoke test, set `MONO_KEY` or `MONO_ENV_FILE`:

```bash
MONO_KEY=... composer test:live
MONO_ENV_FILE=/path/to/.env composer test:live
```

To verify live invoice and subscription creation with a test merchant key:

```bash
MONO_KEY=... MONO_LIVE_CREATE_CONFIRM=yes composer test:live-create
MONO_ENV_FILE=/path/to/.env MONO_LIVE_CREATE_CONFIRM=yes composer test:live-create
```

The live create smoke test uses a minimal amount and attempts cleanup after
creation. Set `MONO_LIVE_CREATE_CLEANUP=false` only when you intentionally want
to retain the created test resources.

## License

MIT
