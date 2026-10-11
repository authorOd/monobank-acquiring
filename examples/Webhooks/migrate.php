<?php

/**
 * Creates the webhook tables once, before the demos run:
 *
 *   php examples/Webhooks/migrate.php
 *
 * Picks schema.sql (SQLite), schema.mysql.sql (MySQL/MariaDB), or
 * schema.pgsql.sql (PostgreSQL) for the configured MONO_WEBHOOK_DSN. The
 * schemas use IF NOT EXISTS, so running this again is harmless; existing
 * installations still need explicit migrations for new columns or indexes.
 */

declare(strict_types=1);

/** @var PDO $pdo */
$pdo = require __DIR__ . '/bootstrap.php';

$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$schemas = [
    'sqlite' => 'schema.sql',
    'mysql' => 'schema.mysql.sql',
    'pgsql' => 'schema.pgsql.sql',
];

if (!isset($schemas[$driver])) {
    fwrite(STDERR, "Unsupported PDO driver {$driver}; use SQLite, MySQL/MariaDB, or PostgreSQL.\n");
    exit(1);
}

$pdo->exec((string) file_get_contents(__DIR__ . '/' . $schemas[$driver]));

echo "Applied {$schemas[$driver]} ({$driver})\n";
