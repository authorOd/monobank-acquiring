<?php

namespace Vladchornyi\Mono\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Installs this library into a throw-away Composer project exactly as a
 * consumer would (path repository, no dev autoload) and checks what loads:
 * the SDK does, the examples do not, and the documented copy procedure for
 * the examples works.
 */
final class ComposerConsumerTest extends TestCase
{
    private string $project;
    private string $composer;

    protected function setUp(): void
    {
        $composer = trim((string) shell_exec('command -v composer 2>/dev/null'));

        if ($composer === '') {
            self::markTestSkipped('composer binary is required.');
        }

        $this->composer = $composer;
        $this->project = sys_get_temp_dir() . '/mono-consumer-' . bin2hex(random_bytes(4));
        mkdir($this->project . '/src/Payments/Monobank', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->shell('rm -rf ' . escapeshellarg($this->project));
    }

    public function testConsumerInstallLoadsTheSdkButNotTheExamplesUntilCopied(): void
    {
        $library = dirname(__DIR__, 2);
        file_put_contents($this->project . '/composer.json', json_encode([
            'name' => 'acme/shop',
            'minimum-stability' => 'dev',
            'repositories' => [['type' => 'path', 'url' => $library, 'options' => ['symlink' => false]]],
            'require' => ['vladchornyi/mono' => '*'],
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->composerRun('install --no-interaction --no-plugins --no-scripts --quiet');
        self::assertFileExists($this->project . '/vendor/vladchornyi/mono/src/MonoClient.php');
        self::assertFileExists(
            $this->project . '/vendor/vladchornyi/mono/examples/Webhooks/TransactionalWebhookProcessor.php',
            'examples ship in the package'
        );

        $probe = <<<'PHP'
            require __DIR__ . '/vendor/autoload.php';
            echo json_encode([
                'sdk' => class_exists(\Vladchornyi\Mono\MonoClient::class),
                'examples' => class_exists(\Vladchornyi\Mono\Examples\Webhooks\TransactionalWebhookProcessor::class),
                'copied' => class_exists(\App\Payments\Monobank\TransactionalWebhookProcessor::class),
            ]);
            PHP;

        self::assertSame(
            ['sdk' => true, 'examples' => false, 'copied' => false],
            $this->probe($probe),
            'dev autoload of the library must not leak into consumers; docs must not rely on it'
        );

        // Execute the actual published commands, including the platform's sed.
        foreach (['examples/Webhooks/README.md', 'docs/webhooks.md'] as $document) {
            preg_match_all('/```bash\n(.*?)```/s', (string) file_get_contents($library . '/' . $document), $matches);
            $blocks = array_values(array_filter($matches[1], static fn (string $block): bool =>
                str_contains($block, 'cp vendor/vladchornyi/mono/examples/Webhooks/')));
            self::assertCount(1, $blocks, $document . ' must have one copy procedure');
            $script = $this->project . '/copy-examples.sh';
            file_put_contents($script, "set -eu\n" . $blocks[0]);
            $this->shell('cd ' . escapeshellarg($this->project) . ' && bash ' . escapeshellarg($script));
            self::assertStringContainsString(
                'namespace App\Payments\Monobank;',
                (string) file_get_contents($this->project . '/src/Payments/Monobank/OutboxWorker.php')
            );
            foreach (['schema.sql', 'schema.mysql.sql', 'schema.pgsql.sql'] as $schema) {
                self::assertFileExists($this->project . '/src/Payments/Monobank/' . $schema);
            }
        }
        $this->composerRun('dump-autoload --quiet');

        $usage = <<<'PHP'
            require __DIR__ . '/vendor/autoload.php';
            $pdo = new PDO('sqlite::memory:');
            $pdo->exec(file_get_contents(__DIR__ . '/src/Payments/Monobank/schema.sql'));
            $processor = new \App\Payments\Monobank\TransactionalWebhookProcessor(
                $pdo,
                \Vladchornyi\Mono\Services\WebhookService::create()
            );
            $worker = new \App\Payments\Monobank\OutboxWorker($pdo, static function (array $job): void {});
            echo json_encode([
                'copied' => class_exists(\App\Payments\Monobank\TransactionalWebhookProcessor::class),
                'processor' => get_class($processor),
                'jobs' => $worker->runOnce(),
            ]);
            PHP;

        self::assertSame(
            [
                'copied' => true,
                'processor' => 'App\Payments\Monobank\TransactionalWebhookProcessor',
                'jobs' => 0,
            ],
            $this->probe($usage)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $code): array
    {
        $file = $this->project . '/probe.php';
        file_put_contents($file, "<?php\n" . $code);
        $output = $this->shell(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file));
        $decoded = json_decode(trim($output), true);

        self::assertIsArray($decoded, 'probe output: ' . $output);

        return $decoded;
    }

    private function composerRun(string $arguments): void
    {
        $env = 'COMPOSER_HOME=' . escapeshellarg($this->project . '/.composer')
            . ' COMPOSER_NO_INTERACTION=1 COMPOSER_DISABLE_NETWORK=1';
        $this->shell(
            'cd ' . escapeshellarg($this->project) . ' && ' . $env . ' '
            . escapeshellarg($this->composer) . ' ' . $arguments
        );
    }

    private function shell(string $command): string
    {
        exec($command . ' 2>&1', $lines, $code);
        $output = implode("\n", $lines);

        self::assertSame(0, $code, "command failed: {$command}\n{$output}");

        return $output;
    }
}
