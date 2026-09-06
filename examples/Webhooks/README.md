# Transactional webhook handler (copy-and-own template)

These files ship inside the Composer package but are **not autoloaded** when
`vladchornyi/mono` is installed as a dependency: the `Vladchornyi\Mono\Examples`
namespace is registered under `autoload-dev`, which Composer only uses for the
library's own development. They are templates you copy into your application,
because a real handler depends on your database, framework, and job runner.

## Copy into your application

```bash
mkdir -p src/Payments/Monobank
cp vendor/vladchornyi/mono/examples/Webhooks/{TransactionalWebhookProcessor.php,OutboxWorker.php,schema*.sql} src/Payments/Monobank/
sed -i.bak 's/namespace Vladchornyi\\Mono\\Examples\\Webhooks;/namespace App\\Payments\\Monobank;/' src/Payments/Monobank/*.php
```

Make sure `App\Payments\Monobank` is covered by your PSR-4 autoload (for a
standard Composer layout, `"App\\": "src/"` already does) and run
`composer dump-autoload`. The `sed -i.bak` command works on both macOS and Linux
and leaves `.bak` copies of the original PHP files.

Apply the matching schema with your migration tool: `schema.sql` for SQLite,
`schema.mysql.sql` for MySQL/MariaDB (InnoDB), or `schema.pgsql.sql` for PostgreSQL.
These files create fresh tables; an existing installation must add any missing
columns/indexes through its own migration rather than relying on `IF NOT EXISTS`.

## Database verification

`tests/Integration/OutboxWorkerTest.php` runs on SQLite by default. CI also runs
the same ordering, lease, starvation, rollback, and duplicate-delivery tests
against MySQL 8.0, MariaDB 10.5, and PostgreSQL 16 using native PDO prepares.
Each schema is applied twice to check that table and index creation is idempotent.

To run it against a local **disposable** database named `mono_test`:

```bash
MONO_WEBHOOK_TEST_DSN='mysql:host=127.0.0.1;port=3306;dbname=mono_test' \
MONO_WEBHOOK_TEST_USER=root MONO_WEBHOOK_TEST_PASSWORD=test-password \
php vendor/bin/phpunit --fail-on-skipped tests/Integration/OutboxWorkerTest.php
```

For PostgreSQL use a `pgsql:` DSN and the appropriate user. The tests delete the
contents of the three `webhook_*` tables; never use an application database.

## Bootstrap

`index.php` and `worker.php` are stand-alone demos for `php -S`; they locate
`vendor/autoload.php` by walking up from their own directory. In an application
you wire the two classes into your HTTP controller and your queue/cron runner:

```php
use App\Payments\Monobank\TransactionalWebhookProcessor;

$processor = new TransactionalWebhookProcessor($pdo, $mono->webhooks());
$outcome = $processor->handle($rawBody, $xSign);   // applied | duplicate | stale | retry
http_response_code($outcome === TransactionalWebhookProcessor::OUTCOME_RETRY ? 503 : 200);
```

`tests/Integration/ComposerConsumerTest.php` in the library repository installs
the package as a dependency and verifies both the non-autoloading and the copy
procedure above.
