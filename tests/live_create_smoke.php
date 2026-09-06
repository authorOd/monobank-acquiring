<?php

use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\Models\InvoiceData;
use Vladchornyi\Mono\Models\SubscriptionData;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Support\SensitiveData;
use Vladchornyi\Mono\Support\PackageVersion;

require __DIR__ . '/../vendor/autoload.php';

function read_live_create_key_from_env_file(?string $path): ?string
{
    if ($path === null || $path === '' || !is_readable($path)) {
        return null;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_starts_with($line, 'MONO_KEY=')) {
            continue;
        }

        $value = trim(substr($line, strlen('MONO_KEY=')));
        $value = trim($value, "\"'");

        return $value !== '' ? $value : null;
    }

    return null;
}

function live_create_key_source(): array
{
    $envKey = getenv('MONO_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return [$envKey, 'MONO_KEY'];
    }

    $envFileKey = read_live_create_key_from_env_file(getenv('MONO_ENV_FILE') ?: null);
    if ($envFileKey !== null) {
        return [$envFileKey, 'MONO_ENV_FILE'];
    }

    return [null, null];
}

function live_create_result(string $name, callable $callback): array
{
    try {
        return array_merge(['name' => $name, 'ok' => true], $callback());
    } catch (Throwable $e) {
        $result = [
            'name' => $name,
            'ok' => false,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
        ];

        if ($e instanceof MonoApiException) {
            $result['status'] = $e->getStatusCode();
            $result['response'] = SensitiveData::sanitize($e->getResponseData() ?? []);
        }

        return $result;
    }
}

function live_create_boolean_env(string $name, bool $default): bool
{
    $value = getenv($name);

    if ($value === false || $value === '') {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (!is_bool($parsed)) {
        throw new InvalidArgumentException("{$name} must be one of: true, false, 1, 0, yes, no, on, off.");
    }

    return $parsed;
}

[$key, $source] = live_create_key_source();

if ($key === null) {
    echo json_encode([
        'status' => 'skipped',
        'reason' => 'Set MONO_KEY or MONO_ENV_FILE to run live create smoke tests.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

if (getenv('MONO_LIVE_CREATE_CONFIRM') !== 'yes') {
    echo json_encode([
        'status' => 'skipped',
        'reason' => 'Set MONO_LIVE_CREATE_CONFIRM=yes to acknowledge that this test creates live API resources.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

$client = new MonoClient(
    apiKey: $key,
    httpOptions: [
        'timeout' => 15,
        'connect_timeout' => 5,
        'user_agent' => 'vladchornyi-mono-live-create-smoke/' . PackageVersion::get(),
    ]
);

$cleanup = live_create_boolean_env('MONO_LIVE_CREATE_CLEANUP', true);

$checks = [];

$checks[] = live_create_result('create_invoice', function () use ($client, $cleanup): array {
    $invoice = $client->invoices()->createInvoice(new InvoiceData(
        amount: 100,
        redirectUrl: 'https://example.com/monobank/return',
        webHookUrl: 'https://example.com/webhooks/monobank/invoice',
        ccy: 980,
        validity: 3600,
        paymentType: 'debit'
    ));

    $invoiceId = $invoice['invoiceId'] ?? null;
    $pageUrl = $invoice['pageUrl'] ?? null;
    $hasInvoiceId = is_string($invoiceId) && $invoiceId !== '';
    $hasPageUrl = is_string($pageUrl) && $pageUrl !== '';
    $removed = null;
    $cleanupError = null;

    if ($cleanup && $hasInvoiceId) {
        try {
            $client->invoices()->removeInvoice($invoiceId);
            $removed = true;
        } catch (Throwable $e) {
            $removed = false;
            $cleanupError = get_class($e) . ': ' . $e->getMessage();
        }
    }

    return [
        'ok' => $hasInvoiceId && $hasPageUrl && $removed !== false,
        'has_invoice_id' => $hasInvoiceId,
        'has_page_url' => $hasPageUrl,
        'invoice_id_prefix' => is_string($invoiceId) ? substr($invoiceId, 0, 4) : null,
        'invoice_id_for_manual_cleanup' => (!$cleanup || $removed === false) ? $invoiceId : null,
        'cleanup_removed' => $removed,
        'cleanup_error' => $cleanupError,
        'response_keys' => array_keys($invoice),
    ];
});

$checks[] = live_create_result('create_subscription', function () use ($client, $cleanup): array {
    $subscription = $client->subscriptions()->createSubscription(new SubscriptionData(
        amount: 100,
        ccy: 980,
        redirectUrl: 'https://example.com/monobank/subscription/return',
        webHookUrls: [
            'chargeUrl' => 'https://example.com/webhooks/monobank/subscription/charge',
            'statusUrl' => 'https://example.com/webhooks/monobank/subscription/status',
        ],
        interval: '1m',
        validity: 3600
    ));

    $subscriptionId = $subscription['subscriptionId'] ?? null;
    $pageUrl = $subscription['pageUrl'] ?? null;
    $hasSubscriptionId = is_string($subscriptionId) && $subscriptionId !== '';
    $hasPageUrl = is_string($pageUrl) && $pageUrl !== '';
    $removed = null;
    $cleanupError = null;

    if ($cleanup && $hasSubscriptionId) {
        try {
            $client->subscriptions()->removeSubscription($subscriptionId);
            $removed = true;
        } catch (Throwable $e) {
            $removed = false;
            $cleanupError = get_class($e) . ': ' . $e->getMessage();
        }
    }

    return [
        'ok' => $hasSubscriptionId && $hasPageUrl && $removed !== false,
        'has_subscription_id' => $hasSubscriptionId,
        'has_page_url' => $hasPageUrl,
        'subscription_id_prefix' => is_string($subscriptionId) ? substr($subscriptionId, 0, 4) : null,
        'subscription_id_for_manual_cleanup' => (!$cleanup || $removed === false) ? $subscriptionId : null,
        'cleanup_removed' => $removed,
        'cleanup_error' => $cleanupError,
        'response_keys' => array_keys($subscription),
    ];
});

$ok = array_reduce(
    $checks,
    static fn(bool $carry, array $check): bool => $carry && $check['ok'] === true,
    true
);

echo json_encode([
    'status' => $ok ? 'passed' : 'failed',
    'key_source' => $source,
    'cleanup' => $cleanup,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;

exit($ok ? 0 : 1);
