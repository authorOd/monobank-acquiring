<?php

/**
 * Shared setup for the stand-alone demos (index.php, worker.php, migrate.php):
 * loads Composer and returns a PDO connection.
 *
 * The database location comes from the environment, never from this
 * directory, so copying the demo into a public web root cannot expose the
 * database file:
 *
 *   MONO_WEBHOOK_DSN       PDO DSN, e.g. 'pgsql:host=127.0.0.1;dbname=shop'
 *                          (default: SQLite file in the system temp directory)
 *   MONO_WEBHOOK_USER      database user (optional)
 *   MONO_WEBHOOK_PASSWORD  database password (optional)
 *
 * Tables are created once by `php examples/Webhooks/migrate.php` (or your own
 * migration tool), not on every request.
 */

declare(strict_types=1);

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

$env = static function (string $name): ?string {
    $value = $_ENV[$name] ?? getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
};

$dsn = $env('MONO_WEBHOOK_DSN')
    ?? 'sqlite:' . rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mono-webhooks.sqlite';

return new PDO($dsn, $env('MONO_WEBHOOK_USER'), $env('MONO_WEBHOOK_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
