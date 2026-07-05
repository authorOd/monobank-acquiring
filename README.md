# Monobank Acquiring PHP Client

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![Total Downloads](https://img.shields.io/packagist/dt/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![PHP Version](https://img.shields.io/packagist/php-v/vladchornyi/mono.svg)](https://packagist.org/packages/vladchornyi/mono)
[![License](https://img.shields.io/packagist/l/vladchornyi/mono.svg)](LICENSE)
[![GitHub Release](https://img.shields.io/github/v/release/authorOd/monobank-acquiring?display_name=tag)](https://github.com/authorOd/monobank-acquiring/releases)

Production-ready PHP client for the Monobank Acquiring API: invoices,
subscriptions, statements, merchant details, webhook signature verification, and
structured error handling.

## Features

- Invoices: create, status, cancel, invalidate, and hold finalization.
- Subscriptions: create, status, list, payment history, cancel/edit.
- Statements and merchant details.
- Verified webhook parsing with `X-Sign` support.
- Typed webhook payload helpers for invoice and subscription events.
- Stale webhook protection via `modifiedDate`.
- Replaceable HTTP layer for tests and framework integrations.
- Structured exceptions and safe logging helpers.

## Requirements

- PHP 8.0+
- `ext-curl`
- `ext-json`
- `ext-mbstring`
- `ext-openssl`

## Installation

```bash
composer require vladchornyi/mono:^1.4
```

## Quick Start

```php
use Vladchornyi\Mono\MonoClient;

$mono = new MonoClient($_ENV['MONO_KEY']);

$merchant = $mono->merchant()->getDetails();
$publicKey = $mono->pubkey()->get();
```

## Documentation

- [Installation and client setup](docs/installation.md)
- [Invoices](docs/invoices.md)
- [Webhooks](docs/webhooks.md)
- [Subscriptions](docs/subscriptions.md)
- [Statements and merchant details](docs/statements.md)
- [Errors and safe logging](docs/errors-and-logging.md)

## Testing From Source

```bash
composer test
```

The default test suite is self-contained and does not call Monobank.

For an optional live smoke test, set `MONO_KEY` or `MONO_ENV_FILE`:

```bash
MONO_KEY=... composer test:live
MONO_ENV_FILE=/path/to/.env composer test:live
```

To verify live invoice and subscription creation with a test merchant key:

```bash
MONO_KEY=... composer test:live-create
MONO_ENV_FILE=/path/to/.env composer test:live-create
```

The live create smoke test uses a minimal amount and attempts cleanup after
creation.

## License

MIT
