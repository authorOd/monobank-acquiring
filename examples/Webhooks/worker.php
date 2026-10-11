<?php

/**
 * Outbox worker: runs queued side effects after webhook transactions committed.
 *
 *   php examples/Webhooks/migrate.php   # once: create the tables
 *   php examples/Webhooks/worker.php
 */

declare(strict_types=1);

use Vladchornyi\Mono\Examples\Webhooks\OutboxWorker;

/** @var PDO $pdo */
$pdo = require __DIR__ . '/bootstrap.php';   // tables come from migrate.php, not from every run

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
