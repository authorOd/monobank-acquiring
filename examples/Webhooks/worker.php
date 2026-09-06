<?php

/**
 * Outbox worker: runs queued side effects after webhook transactions committed.
 *
 *   php examples/Webhooks/worker.php
 */

declare(strict_types=1);

use Vladchornyi\Mono\Examples\Webhooks\OutboxWorker;

// Works from the library checkout (examples/Webhooks) and from a copy inside an application.
$autoload = null;
for ($dir = __DIR__; $dir !== dirname($dir); $dir = dirname($dir)) {
    if (is_file($dir . '/vendor/autoload.php')) {
        $autoload = $dir . '/vendor/autoload.php';
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "vendor/autoload.php not found above " . __DIR__ . "; run composer install\n");
    exit(1);
}

require $autoload;

$pdo = new PDO('sqlite:' . __DIR__ . '/webhooks.sqlite');
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));   // idempotent CREATE IF NOT EXISTS

$worker = new OutboxWorker($pdo, static function (array $job): void {
    // Use $job['idempotency_key'] as the idempotency key of every external call so a
    // re-run after a crash cannot duplicate the effect.
    switch ($job['job_type']) {
        case 'invoice.success':
        case 'subscription_charge.success':
            // $orders->markPaid($job['payload']['invoiceId'], $job['idempotency_key']);
            break;
        case 'invoice.failure':
        case 'invoice.expired':
        case 'invoice.reversed':
            // $orders->markFailed($job['payload']['invoiceId'], $job['idempotency_key']);
            break;
        case 'invoice.wallet.created':
            // $wallets->storeCardToken($job['payload']['walletData'], $job['idempotency_key']);
            break;
        default:
            // Unknown types are ignored but still marked done.
    }
});

echo $worker->runOnce(), " job(s) completed\n";
