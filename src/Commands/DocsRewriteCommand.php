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
            )
            ->addOption(
                'overrides-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to a directory with override markdown files (same relative layout as docs-path, applied over docs-path)',
                'overrides'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Markdown AST Rewriter');

        $rawDocsPath = $input->getOption('docs-path');
        if (empty($rawDocsPath)) {
            $io->error('Please specify the path to laravel/docs markdown files using --docs-path');
            return Command::FAILURE;
        }

        $resolvedDocsPath = realpath((string) $rawDocsPath);
        if ($resolvedDocsPath === false || !is_dir($resolvedDocsPath)) {
            $io->error("Directory does not exist: {$rawDocsPath}");
            return Command::FAILURE;
        }
        $docsPath = $resolvedDocsPath;

        $indexPath = (string) $input->getOption('index-path');
        if (!file_exists($indexPath)) {
            $io->error("Symbols index file not found: {$indexPath}. Please run 'build:index' first.");
            return Command::FAILURE;
        }

        $outputPath = (string) $input->getOption('output-path');
        $dryRun = (bool) $input->getOption('dry-run');

        // Overrides mirror CI's "Apply Overrides" step so local preview matches deploys.
        $rawOverridesPath = $input->getOption('overrides-path');
        $overridesPath = null;
        if (is_string($rawOverridesPath) && $rawOverridesPath !== '') {
            $resolvedOverrides = realpath($rawOverridesPath);
            if ($resolvedOverrides !== false && is_dir($resolvedOverrides)) {
                $overridesPath = $resolvedOverrides;
            }
        }

        $io->section('Configuration');
        $configLines = [
            "Documentation Source: <info>{$docsPath}</info>",
            "Symbols Index: <info>{$indexPath}</info>",
            "Output Directory: <info>{$outputPath}</info>" . ($dryRun ? ' (DRY RUN - No files will be written)' : ''),
        ];
        if ($overridesPath !== null) {
            $configLines[] = "Overrides: <info>{$overridesPath}</info>";
        }
        $io->listing($configLines);

        try {
            $registry = SymbolRegistry::loadFromJson($indexPath);
            $io->info(sprintf('Loaded %d indexed symbols from %s', $registry->count(), $indexPath));

            $matcher = new SymbolMatcher($registry);
            // Record which page + section each symbol was tagged in, so search
            // results can deep-link to the documentation instead of the API page.
            // First occurrence per page wins (file order is top-down).
            $symbolPages = [];
            $rewriter = new MarkdownRewriter($matcher, function (string $symbol, ?string $slug, ?string $anchor) use (&$symbolPages): void {
                if ($slug === null || $slug === '') {
                    return;
                }
                if (!isset($symbolPages[$symbol][$slug])) {
                    $symbolPages[$symbol][$slug] = $anchor;
                }
            });

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

            // Overlay override-only files so `overrides/foo.md` behaves like CI's `cp overrides/* docs/`.
            if ($overridesPath !== null) {
                $overrideIterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($overridesPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                $seen = [];
                foreach ($files as $f) {
                    $seen[ltrim(substr($f, strlen($docsPath)), '/\\')] = true;
                }
                foreach ($overrideIterator as $item) {
                    if (!$item->isFile() || $item->getExtension() !== 'md') {
                        continue;
                    }
                    $rel = ltrim(substr($item->getPathname(), strlen($overridesPath)), '/\\');
                    if (!isset($seen[$rel])) {
                        $files[] = $overridesPath . DIRECTORY_SEPARATOR . $rel;
                        $seen[$rel] = true;
                    }
                }
                // Keep output deterministic.
                sort($files);
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
                $isOverrideOnly = $overridesPath !== null && str_starts_with($sourcePath, $overridesPath . DIRECTORY_SEPARATOR);
                $baseForRelative = $isOverrideOnly ? $overridesPath : $docsPath;
                $relativePath = ltrim(substr($sourcePath, strlen($baseForRelative)), '/\\');
                $targetPath = rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . $relativePath;

                // If an override exists for this relative path, it wins (matches CI copy order).
                $effectiveSource = $sourcePath;
                if (!$isOverrideOnly && $overridesPath !== null) {
                    $overrideCandidate = $overridesPath . DIRECTORY_SEPARATOR . $relativePath;
                    if (is_file($overrideCandidate)) {
                        $effectiveSource = $overrideCandidate;
                    }
                }

                $content = file_get_contents($effectiveSource);
                if ($content === false) {
                    throw new \RuntimeException("Unable to read file: {$effectiveSource}");
                }
                $docSlug = basename($relativePath, '.md');
                $rewritten = $rewriter->rewrite($content, $docSlug);
                $isModified = ($content !== $rewritten);
                $tagsCount = substr_count($rewritten, '<x-since');

                if (!$dryRun) {
                    $targetDir = dirname($targetPath);
                    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                        throw new \RuntimeException("Unable to create target directory: {$targetDir}");
                    }

                    if (file_put_contents($targetPath, $rewritten) === false) {
                        throw new \RuntimeException("Unable to write file: {$targetPath}");
                    }
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

            $pagesWritten = 0;
            if (!$dryRun) {
                // Persist the symbol -> docs-page map next to the rewritten
                // output so search (serve + static) can deep-link rows to docs.
                // Primary page is the first alphabetically (deterministic).
                $pagesMap = [];
                foreach ($symbolPages as $symbol => $slugs) {
                    ksort($slugs);
                    $primarySlug = (string) array_key_first($slugs);
                    $primaryAnchor = $slugs[$primarySlug] ?? null;
                    $pagesMap[$symbol] = [
                        'page' => $primarySlug,
                        'anchor' => is_string($primaryAnchor) && $primaryAnchor !== '' ? $primaryAnchor : null,
                        'pages' => array_keys($slugs),
                    ];
                }
                ksort($pagesMap);
                $pagesJson = json_encode($pagesMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($pagesJson !== false) {
                    $pagesPath = rtrim($outputPath, '/\\') . DIRECTORY_SEPARATOR . 'symbol_pages.json';
                    if (file_put_contents($pagesPath, $pagesJson) !== false) {
                        $pagesWritten = count($pagesMap);
                    }
                }
            }

            $io->success([
                $dryRun ? 'Documentation dry-run rewrite completed!' : 'Documentation rewrite completed successfully!',
                "Total markdown files scanned: {$filesScanned}",
                "Files modified / enriched: {$filesModified}",
                "Total <x-since> tags present: {$totalTags}",
                $dryRun ? 'Dry run mode: no files written' : "Output written to: {$outputPath}",
                $dryRun ? 'Dry run mode: symbol map not written' : "Symbols mapped to docs pages: {$pagesWritten}",
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
