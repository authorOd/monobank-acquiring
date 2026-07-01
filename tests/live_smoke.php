<?php

use Vladchornyi\Mono\Exceptions\MonoApiException;
use Vladchornyi\Mono\MonoClient;

require __DIR__ . '/../vendor/autoload.php';

function read_key_from_env_file(?string $path): ?string
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

function key_source(): array
{
    $envKey = getenv('MONO_KEY');
    if (is_string($envKey) && $envKey !== '') {
        return [$envKey, 'MONO_KEY'];
    }

    $envFileKey = read_key_from_env_file(getenv('MONO_ENV_FILE') ?: null);
    if ($envFileKey !== null) {
        return [$envFileKey, 'MONO_ENV_FILE'];
    }

    return [null, null];
}

[$key, $source] = key_source();

if ($key === null) {
    echo json_encode([
        'status' => 'skipped',
        'reason' => 'Set MONO_KEY or MONO_ENV_FILE to run live Monobank smoke tests.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(2);
}

$client = new MonoClient(
    apiKey: $key,
    httpOptions: [
        'timeout' => 10,
        'connect_timeout' => 5,
        'user_agent' => 'vladchornyi-mono-live-smoke/1.4',
    ]
);

$checks = [];

try {
    $pubkey = $client->pubkey()->get();
    $checks[] = [
        'name' => 'pubkey',
        'ok' => isset($pubkey['key']) && is_string($pubkey['key']) && $pubkey['key'] !== '',
        'keys' => array_keys($pubkey),
    ];
} catch (Throwable $e) {
    $checks[] = [
        'name' => 'pubkey',
        'ok' => false,
        'exception' => get_class($e),
        'status' => $e instanceof MonoApiException ? $e->getStatusCode() : null,
        'message' => $e->getMessage(),
    ];
}

try {
    $details = $client->merchant()->getDetails();
    $checks[] = [
        'name' => 'merchant_details',
        'ok' => $details !== [],
        'keys' => array_keys($details),
    ];
} catch (Throwable $e) {
    $checks[] = [
        'name' => 'merchant_details',
        'ok' => false,
        'exception' => get_class($e),
        'status' => $e instanceof MonoApiException ? $e->getStatusCode() : null,
        'message' => $e->getMessage(),
    ];
}

$ok = array_reduce(
    $checks,
    static fn(bool $carry, array $check): bool => $carry && $check['ok'] === true,
    true
);

echo json_encode([
    'status' => $ok ? 'passed' : 'failed',
    'key_source' => $source,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;

exit($ok ? 0 : 1);
