<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Integration;

use LaravelProDocs\Commands\DocsRewriteCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DocsRewriteCommandTest extends TestCase
{
    private string $docsSource;
    private string $docsOutput;
    private string $indexFile;

    protected function setUp(): void
    {
        $this->docsSource = sys_get_temp_dir() . '/docs_src_' . uniqid();
        $this->docsOutput = sys_get_temp_dir() . '/docs_out_' . uniqid();
        $this->indexFile = sys_get_temp_dir() . '/index_' . uniqid() . '.json';

        mkdir($this->docsSource, 0755, true);

        // Prepare mock index
        $indexData = [
            'Number::currency' => [
                'version' => 'v10.38.0',
                'pr' => 49451,
                'pr_url' => 'https://github.com/laravel/framework/pull/49451',
            ],
            'rescue' => [
                'version' => 'v9.0.0',
                'pr' => null,
                'pr_url' => null,
            ],
        ];
        file_put_contents($this->indexFile, json_encode($indexData, JSON_PRETTY_PRINT));

        // Prepare mock markdown file
        $docContent = <<<'MD'
# Helper Functions

### Number::currency

Format numbers using `Number::currency($amount)`.
Use `rescue()` to catch exceptions.
Generic `->get()` stays untouched.
MD;
        file_put_contents($this->docsSource . '/helpers.md', $docContent);
    }

    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg($this->docsSource));
        shell_exec('rm -rf ' . escapeshellarg($this->docsOutput));
        if (file_exists($this->indexFile)) {
            unlink($this->indexFile);
        }
    }

    public function testDocsRewriteCommandExecutesSuccessfully(): void
    {
        $app = new Application();
        $app->add(new DocsRewriteCommand());

        $command = $app->find('docs:rewrite');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--docs-path' => $this->docsSource,
            '--index-path' => $this->indexFile,
            '--output-path' => $this->docsOutput,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->docsOutput . '/helpers.md');

        $rewritten = (string) file_get_contents($this->docsOutput . '/helpers.md');
        $this->assertStringContainsString('### Number::currency <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" />', $rewritten);
        $this->assertStringContainsString('`Number::currency($amount)` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" />', $rewritten);
        $this->assertStringContainsString('`rescue()` <x-since v="v9.0.0" />', $rewritten);
        $this->assertStringContainsString('`->get()` stays untouched.', $rewritten);
    }

    public function testDryRunModeDoesNotWriteFiles(): void
    {
        $app = new Application();
        $app->add(new DocsRewriteCommand());

        $command = $app->find('docs:rewrite');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--docs-path' => $this->docsSource,
            '--index-path' => $this->indexFile,
            '--output-path' => $this->docsOutput,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileDoesNotExist($this->docsOutput . '/helpers.md');
        $this->assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    public function testFailsWhenIndexPathIsMissing(): void
    {
        $app = new Application();
        $app->add(new DocsRewriteCommand());

        $command = $app->find('docs:rewrite');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--docs-path' => $this->docsSource,
            '--index-path' => '/non/existent/index.json',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Symbols index file not found', $tester->getDisplay());
    }
}
