<?php

namespace Vladchornyi\Mono\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Vladchornyi\Mono\MonoClient;
use Vladchornyi\Mono\Tests\Support\OpenApiSpec;

/**
 * Keeps the documentation honest: every PHP block must compile, every
 * `$mono->service()->method(` reference must exist, the API coverage matrix
 * must list every endpoint of the specification exactly once, and referenced
 * example files must exist.
 */
final class DocumentationConsistencyTest extends TestCase
{
    private const DOCUMENTS = [
        'README.md',
        'UPGRADING.md',
        'docs/getting-started.md',
        'docs/installation.md',
        'docs/invoices.md',
        'docs/subscriptions.md',
        'docs/webhooks.md',
        'docs/statements.md',
        'docs/errors-and-logging.md',
        'docs/api-coverage.md',
    ];

    public function testEveryPhpBlockCompiles(): void
    {
        $blocks = 0;

        foreach (self::DOCUMENTS as $document) {
            preg_match_all('/```php\n(.*?)```/s', $this->read($document), $matches);

            foreach ($matches[1] as $index => $block) {
                ++$blocks;
                $file = tempnam(sys_get_temp_dir(), 'mono-doc-');
                file_put_contents($file, "<?php\n" . $block);
                exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $code);
                unlink($file);

                self::assertSame(0, $code, sprintf('%s block #%d: %s', $document, $index + 1, implode("\n", $output)));
            }
        }

        self::assertGreaterThan(30, $blocks);
    }

    public function testEveryDocumentedClientCallExists(): void
    {
        $client = new \ReflectionClass(MonoClient::class);
        $checked = 0;

        foreach (self::DOCUMENTS as $document) {
            preg_match_all('/\$mono->([a-z]+)\(\)->([A-Za-z]+)\(/', $this->read($document), $matches, PREG_SET_ORDER);

            foreach ($matches as [$call, $service, $method]) {
                self::assertTrue($client->hasMethod($service), "{$document}: {$call}");
                $serviceClass = (string) $client->getMethod($service)->getReturnType();
                self::assertTrue(
                    (new \ReflectionClass($serviceClass))->hasMethod($method),
                    "{$document}: {$call} does not exist on {$serviceClass}"
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(20, $checked);
    }

    public function testCoverageMatrixListsEveryEndpointOfTheSpecificationExactlyOnce(): void
    {
        $matrix = $this->read('docs/api-coverage.md');
        $spec = OpenApiSpec::load();

        self::assertStringContainsString('`' . $spec->version() . '`', $matrix, 'matrix names the spec version');

        foreach ($spec->operations() as $path => $methods) {
            foreach ($methods as $method) {
                $needle = "`{$method} {$path}`";
                self::assertSame(
                    1,
                    substr_count($matrix, $needle),
                    "{$needle} must appear exactly once in docs/api-coverage.md"
                );
            }
        }
    }

    public function testImplementedRowsOfTheCoverageMatrixResolveToRealMethods(): void
    {
        $matrix = $this->read('docs/api-coverage.md');
        $start = (int) strpos($matrix, '## Implemented');
        $implemented = substr($matrix, $start, (int) strpos($matrix, '## Partial') - $start);
        preg_match_all('/`([a-z]+)\(\)->([A-Za-z]+)\(/', $implemented, $matches, PREG_SET_ORDER);
        $client = new \ReflectionClass(MonoClient::class);

        self::assertNotEmpty($matches);

        foreach ($matches as [$call, $service, $method]) {
            $serviceClass = (string) $client->getMethod($service)->getReturnType();
            self::assertTrue((new \ReflectionClass($serviceClass))->hasMethod($method), $call);
        }
    }

    public function testReferencedExampleFilesExist(): void
    {
        foreach (self::DOCUMENTS as $document) {
            preg_match_all('/\(([^)\s]*examples\/[^)\s]*)\)/', $this->read($document), $matches);

            foreach ($matches[1] as $reference) {
                $path = dirname(__DIR__, 2) . '/' . ltrim(str_replace('../', '', $reference), '/');
                self::assertFileExists($path, "{$document} references {$reference}");
            }
        }

        $exampleFiles = [
            'index.php',
            'worker.php',
            'schema.sql',
            'TransactionalWebhookProcessor.php',
            'OutboxWorker.php',
        ];

        foreach ($exampleFiles as $file) {
            self::assertFileExists(dirname(__DIR__, 2) . '/examples/Webhooks/' . $file);
        }
    }

    private function read(string $document): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
    }
}
