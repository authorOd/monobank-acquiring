<?php

/**
 * Minimal HTTP glue for TransactionalWebhookProcessor.
 *
 *   php examples/Webhooks/migrate.php             # once: create the tables
 *   php -S localhost:8080 examples/Webhooks/index.php
 *
 * Run `php examples/Webhooks/worker.php` (or a cron/queue worker) for the outbox.
 * See bootstrap.php for the MONO_WEBHOOK_* database settings.
 */

declare(strict_types=1);

use Vladchornyi\Mono\Examples\Webhooks\TransactionalWebhookProcessor;
use Vladchornyi\Mono\Exceptions\MonoPublicKeyUnavailableException;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\MonoClient;

/** @var PDO $pdo */
$pdo = require __DIR__ . '/bootstrap.php';

$monoKey = $_ENV['MONO_KEY'] ?? getenv('MONO_KEY');

if (!is_string($monoKey) || $monoKey === '') {
    error_log('MONO_KEY is not configured; cannot verify Monobank webhooks.');
    http_response_code(503);          // configuration problem: let Monobank retry later
    exit;
}

$mono = new MonoClient($monoKey);
$processor = new TransactionalWebhookProcessor($pdo, $mono->webhooks());

$rawBody = (string) file_get_contents('php://input');
$xSign = $_SERVER['HTTP_X_SIGN'] ?? null;

try {
    $outcome = $processor->handle($rawBody, $xSign);
} catch (MonoPublicKeyUnavailableException $exception) {
    // The signature was never checked: the public key is temporarily unavailable.
    error_log('Monobank webhook deferred: ' . $exception->getMessage());
    http_response_code(503);          // retryable; never answer 400 here
    exit;
} catch (MonoWebhookException $exception) {
    error_log('Monobank webhook rejected: ' . $exception->getMessage());
    http_response_code(400);          // bad signature/payload: do not retry
    exit;
} catch (Throwable $exception) {
    error_log('Monobank webhook failed: ' . get_class($exception) . ': ' . $exception->getMessage());
    http_response_code(500);          // storage/API failure: Monobank retries
    exit;
}

// Acknowledge only after the transaction committed.
http_response_code($outcome === TransactionalWebhookProcessor::OUTCOME_RETRY ? 503 : 200);
header('Content-Type: application/json');
echo json_encode(['outcome' => $outcome]);
