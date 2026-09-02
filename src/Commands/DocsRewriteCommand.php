<?php

declare(strict_types=1);

namespace LaravelProDocs\Commands;

use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Rewriter\MarkdownRewriter;
use LaravelProDocs\Rewriter\SymbolMatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class DocsRewriteCommand extends Command
{
    protected static $defaultName = 'docs:rewrite';
    protected static $defaultDescription = 'Rewrite Markdown files in laravel/docs to inject version and PR badges';

    protected function configure(): void
    {
        $this
            ->setName('docs:rewrite')
            ->setDescription('Rewrite Markdown files in laravel/docs to inject version and PR badges')
            ->addOption(
                'docs-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the directory containing laravel/docs markdown files',
            )
            ->addOption(
                'index-path',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Path to the symbols index JSON file',
                'storage/symbols_index.json'
            )
            ->addOption(
                'output-path',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Target output directory for rewritten markdown files',
                'output/docs'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate rewriting without writing files to disk'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Markdown AST Rewriter');

        $docsPath = $input->getOption('docs-path');
        if (empty($docsPath)) {
            $io->error('Please specify the path to laravel/docs markdown files using --docs-path');
            return Command::FAILURE;
        }

        $docsPath = (string) realpath($docsPath);
        if (!is_dir($docsPath)) {
            $io->error("Directory does not exist: {$docsPath}");
            return Command::FAILURE;
        }

        $indexPath = (string) $input->getOption('index-path');
        if (!file_exists($indexPath)) {
            $io->error("Symbols index file not found: {$indexPath}. Please run 'build:index' first.");
            return Command::FAILURE;
        }

        $outputPath = (string) $input->getOption('output-path');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->section('Configuration');
        $io->listing([
            "Documentation Source: <info>{$docsPath}</info>",
            "Symbols Index: <info>{$indexPath}</info>",
            "Output Directory: <info>{$outputPath}</info>" . ($dryRun ? ' (DRY RUN - No files will be written)' : ''),
        ]);

        try {
            $registry = SymbolRegistry::loadFromJson($indexPath);
            $io->info(sprintf('Loaded %d indexed symbols from %s', $registry->count(), $indexPath));

            $matcher = new SymbolMatcher($registry);
            $rewriter = new MarkdownRewriter($matcher);

            // Collect all markdown files to calculate progress
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($docsPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isFile() && $item->getExtension() === 'md') {
                    $files[] = $item->getPathname();
                }
            }

            if (empty($files)) {
                $io->warning("No .md markdown files found in {$docsPath}");
                return Command::SUCCESS;
            }

            $progressBar = new ProgressBar($output, count($files));
            $progressBar->setFormat(
                " %current%/%max% [%bar%] %percent:3s%% -- %message% (Tags: %tags%)\n"
            );
            $progressBar->setMessage('Starting markdown rewriting...');
            $progressBar->setMessage('0', 'tags');
            $progressBar->start();

            $filesScanned = 0;
            $filesModified = 0;
            $totalTags = 0;

            foreach ($files as $sourcePath) {
                $relativePath = ltrim(substr($sourcePath, strlen($docsPath)), '/\\');
                $targetPath = rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . $relativePath;

                $content = file_get_contents($sourcePath);
                $docSlug = basename($relativePath, '.md');
                $rewritten = $rewriter->rewrite($content, $docSlug);
                $isModified = ($content !== $rewritten);
                $tagsCount = substr_count($rewritten, '<x-since');

                if (!$dryRun) {
                    $targetDir = dirname($targetPath);
                    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        throw new \RuntimeException("Unable to create target directory: {$targetDir}");
                    }
                    
                    if ($docSlug === 'installation') {
                        $intro = "\n> **Welcome to Laravel Pro Docs**\n> \n> You are viewing an enriched version of the official [Laravel documentation](https://laravel.com/docs). This documentation includes **Version & PR Badges** generated by the [Laravel Pro Docs CLI tool](https://github.com/akshit-arora/laravel-pro-docs). \n> \n> Whenever you see a badge next to a method, class, or heading, it indicates the exact framework release and GitHub Pull Request where that feature was introduced. You can click the badge to jump straight to the source code implementation or PR discussion!\n> \n> Built by [Akshit Arora](https://github.com/akshit-arora) ([@akshitarora0907](https://x.com/akshitarora0907)).\n";
                        $rewritten = preg_replace('/^# Installation\s*/im', "# Installation\n" . $intro . "\n", $rewritten);
                    }
                    
                    file_put_contents($targetPath, $rewritten);
                }

                $filesScanned++;
                if ($isModified) {
                    $filesModified++;
                }
                $totalTags += $tagsCount;

                $progressBar->setMessage($relativePath);
                $progressBar->setMessage((string) $totalTags, 'tags');
                $progressBar->advance();
            }

            $progressBar->finish();
            $output->writeln("\n");

            $io->success([
                $dryRun ? 'Documentation dry-run rewrite completed!' : 'Documentation rewrite completed successfully!',
                "Total markdown files scanned: {$filesScanned}",
                "Files modified / enriched: {$filesModified}",
                "Total <x-since> tags present: {$totalTags}",
                $dryRun ? 'Dry run mode: no files written' : "Output written to: {$outputPath}",
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error("Rewriting failed: {$e->getMessage()}");
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
