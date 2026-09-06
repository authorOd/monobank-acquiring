<?php

namespace Vladchornyi\Mono\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Executes the documentation's PHP examples in a throw-away application
 * process (tests/Support/docs-bootstrap.php provides $mono, a stub transport,
 * a signed sample webhook, and no-op services). Linting only proves syntax;
 * this proves the examples run against the current API.
 *
 * Two rewrites are applied to a block: `file_get_contents('php://input')`
 * becomes the signed sample body (php://input is empty in CLI) and
 * `new MonoClient(` becomes `DocsClientFactory::create(`, which keeps the example's
 * arguments but binds the stub transport so nothing reaches Monobank.
 */
final class DocumentationExamplesTest extends TestCase
{
    private const EXECUTABLE_DOCUMENTS = [
        'README.md',
        'docs/getting-started.md',
        'docs/invoices.md',
        'docs/subscriptions.md',
        'docs/statements.md',
    ];

    /**
     * @dataProvider blockProvider
     */
    public function testDocumentationExampleRuns(string $document, int $index, string $block): void
    {
        $workDir = sys_get_temp_dir() . '/mono-doc-run-' . bin2hex(random_bytes(4));
        mkdir($workDir);
        $script = $workDir . '/example.php';
        $code = str_replace(
            ["file_get_contents('php://input')", 'new MonoClient('],
            ['$__docRawBody', '\\Vladchornyi\\Mono\\Tests\\Support\\Docs\\DocsClientFactory::create('],
            $block
        );
        file_put_contents(
            $script,
            "<?php\nrequire " . var_export(dirname(__DIR__) . '/Support/docs-bootstrap.php', true) . ";\n"
            . $code . "\necho \"\\n__DOC_BLOCK_OK__\\n\";\n"
        );

        $command = sprintf(
            'cd %s && %s -d display_errors=1 %s 2>&1',
            escapeshellarg($workDir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        );
        exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);
        exec('rm -rf ' . escapeshellarg($workDir));
        $label = "{$document} block #{$index}";

        self::assertSame(0, $exitCode, "{$label} exited with {$exitCode}:\n{$output}");
        self::assertStringContainsString('__DOC_BLOCK_OK__', $output, "{$label} exited early:\n{$output}");
        self::assertStringNotContainsString('Warning:', $output, "{$label} emitted warnings:\n{$output}");
        self::assertStringNotContainsString('Deprecated:', $output, "{$label} emitted deprecations:\n{$output}");
    }

    /** @return array<string, array{string, int, string}> */
    public function blockProvider(): array
    {
        $cases = [];

        foreach (self::EXECUTABLE_DOCUMENTS as $document) {
            $content = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
            preg_match_all('/```php\n(.*?)```/s', $content, $matches);

            foreach ($matches[1] as $index => $block) {
                $cases["{$document} #" . ($index + 1)] = [$document, $index + 1, $block];
            }
        }

        return $cases;
    }
}
