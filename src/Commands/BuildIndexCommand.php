<?php

declare(strict_types=1);

namespace LaravelProDocs\Commands;

use LaravelProDocs\Git\GitRepository;
use LaravelProDocs\Git\PrExtractor;
use LaravelProDocs\Indexer\AstSymbolExtractor;
use LaravelProDocs\Indexer\DocsHeaderIndexer;
use LaravelProDocs\Indexer\SymbolIndexer;
use LaravelProDocs\Indexer\SymbolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

class BuildIndexCommand extends Command
{
    protected static $defaultName = 'build:index';
    protected static $defaultDescription = 'Scan laravel/framework Git history and AST symbols to build a version/PR index';

    protected function configure(): void
    {
        $this
            ->setName('build:index')
            ->setDescription('Scan laravel/framework Git history and AST symbols to build a version/PR index')
            ->addOption(
                'framework-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the local clone of laravel/framework',
            )
            ->addOption(
                'docs-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to the local clone of laravel/docs (to index page & section header histories)',
            )
            ->addOption(
                'from-tag',
                null,
                InputOption::VALUE_OPTIONAL,
                'Earliest Git release tag to scan from',
                'v9.0.0'
            )
            ->addOption(
                'to-tag',
                null,
                InputOption::VALUE_OPTIONAL,
                'Latest Git release tag to scan to (defaults to latest tag)',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output JSON file path',
                'storage/symbols_index.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Framework Git & AST Symbol Indexer');

        $rawFrameworkPath = $input->getOption('framework-path');
        if (empty($rawFrameworkPath)) {
            $io->error('Please specify the path to a local clone of laravel/framework using --framework-path');
            return Command::FAILURE;
        }

        $resolvedFrameworkPath = realpath((string) $rawFrameworkPath);
        if ($resolvedFrameworkPath === false || !is_dir($resolvedFrameworkPath) || !is_dir($resolvedFrameworkPath . '/.git')) {
            $io->error("Directory is not a valid git repository: {$rawFrameworkPath}");
            return Command::FAILURE;
        }
        $frameworkPath = $resolvedFrameworkPath;

        $fromTag = (string) $input->getOption('from-tag');
        $toTag = $input->getOption('to-tag') ? (string) $input->getOption('to-tag') : null;
        $outputPath = (string) $input->getOption('output');

        $rawDocsPath = $input->getOption('docs-path');
        $docsPath = null;
        if ($rawDocsPath !== null && $rawDocsPath !== '') {
            $resolvedDocsPath = realpath((string) $rawDocsPath);
            if ($resolvedDocsPath === false || !is_dir($resolvedDocsPath)) {
                $io->error("Documentation directory not found: {$rawDocsPath}");
                return Command::FAILURE;
            }
            $docsPath = $resolvedDocsPath;
        }

        $io->section('Configuration');
        $configList = [
            "Framework Repository: <info>{$frameworkPath}</info>",
            "Starting Tag: <info>{$fromTag}</info>",
            "Ending Tag: <info>" . ($toTag ?? 'Latest HEAD') . '</info>',
            "Output Index: <info>{$outputPath}</info>",
        ];
        if ($docsPath) {
            $configList[] = "Documentation Repository: <info>{$docsPath}</info>";
        }
        $io->listing($configList);

        try {
            $git = new GitRepository($frameworkPath);
            $tags = $git->getTags('v*.*.*', $fromTag, $toTag);

            if (empty($tags)) {
                $io->warning("No release tags found matching pattern 'v*.*.*' >= {$fromTag}");
                return Command::FAILURE;
            }

            $io->info(sprintf('Discovered %d release tags to analyze.', count($tags)));

            $progressBar = new ProgressBar($output, count($tags));
            $progressBar->setFormat(
                " %current%/%max% [%bar%] %percent:3s%% -- %message% (New symbols: %new_symbols%)\n"
            );
            $progressBar->setMessage('Initializing analysis...');
            $progressBar->setMessage('0', 'new_symbols');
            $progressBar->start();

            $registry = new SymbolRegistry();
            $indexer = new SymbolIndexer(
                git: $git,
                extractor: new AstSymbolExtractor(),
                prExtractor: new PrExtractor(),
                registry: $registry,
            );

            $indexer->buildIndex(
                fromTag: $fromTag,
                toTag: $toTag,
                progressCallback: function (string $tag, int $currentIndex, int $totalTags, int $newSymbols) use ($progressBar) {
                    $progressBar->setMessage("Processing {$tag}");
                    $progressBar->setMessage((string) $newSymbols, 'new_symbols');
                    $progressBar->advance();
                }
            );

            $progressBar->finish();
            $output->writeln("\n");

            // If docs path provided, index page & section headers
            if ($docsPath && is_dir($docsPath . '/.git')) {
                $io->section('Documentation Page & Section Header Analysis');
                $docHeaderIndexer = new DocsHeaderIndexer($docsPath);

                $docProgressBar = new ProgressBar($output, 105);
                $docProgressBar->setMessage('Analyzing doc branches...');
                $docProgressBar->start();

                $headers = $docHeaderIndexer->indexHeaders(function (string $slug, int $current, int $total) use ($docProgressBar) {
                    $docProgressBar->setMaxSteps($total);
                    $docProgressBar->setMessage("Analyzing {$slug}.md");
                    $docProgressBar->advance();
                });

                $docProgressBar->finish();
                $output->writeln("\n");

                foreach ($headers as $symbolKey => $meta) {
                    $registry->register($symbolKey, $meta->version, $meta->pr, $meta->prUrl);
                }

                $io->info(sprintf('Indexed %d new page and section header introductions from docs.', count($headers)));
            }

            // Save JSON index
            $registry->saveToJson($outputPath);

            $totalSymbols = $registry->count();
            $withPr = 0;
            foreach ($registry->all() as $meta) {
                if ($meta->pr !== null) {
                    $withPr++;
                }
            }

            $io->success([
                "Symbol indexing completed successfully!",
                "Total symbols indexed: {$totalSymbols}",
                "Symbols with PR links: {$withPr}",
                "Saved index to: {$outputPath}",
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error("Indexing failed: {$e->getMessage()}");
            if ($output->isVerbose()) {
                $output->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }
}
