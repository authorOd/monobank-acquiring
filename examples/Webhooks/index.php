<?php

/**
 * Minimal HTTP glue for TransactionalWebhookProcessor.
 *
 *   php -S localhost:8080 examples/Webhooks/index.php
 *
 * Run `php examples/Webhooks/worker.php` (or a cron/queue worker) for the outbox.
 */

declare(strict_types=1);

use Vladchornyi\Mono\Examples\Webhooks\TransactionalWebhookProcessor;
use Vladchornyi\Mono\Exceptions\MonoWebhookException;
use Vladchornyi\Mono\MonoClient;

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
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));

$mono = new MonoClient($_ENV['MONO_KEY'] ?? getenv('MONO_KEY') ?: 'missing');
$processor = new TransactionalWebhookProcessor($pdo, $mono->webhooks());

$rawBody = (string) file_get_contents('php://input');
$xSign = $_SERVER['HTTP_X_SIGN'] ?? null;

try {
    $outcome = $processor->handle($rawBody, $xSign);
} catch (MonoWebhookException $exception) {
    http_response_code(400);          // bad signature/payload: do not retry
    exit;
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    http_response_code(500);          // storage failure: Monobank retries (up to 3 times)
    exit;
}

// Acknowledge only after the transaction committed.
http_response_code($outcome === TransactionalWebhookProcessor::OUTCOME_RETRY ? 503 : 200);
header('Content-Type: application/json');
echo json_encode(['outcome' => $outcome]);
