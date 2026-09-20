<?php

declare(strict_types=1);

namespace LaravelProDocs\Commands;

use LaravelProDocs\Support\BadgeRenderer;
use LaravelProDocs\Support\Layout;
use LaravelProDocs\Support\MarkdownPipeline;
use LaravelProDocs\Support\SearchIndexBuilder;
use LaravelProDocs\Support\SidebarBuilder;
use LaravelProDocs\Support\TocBuilder;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

class DocsBuildStaticCommand extends Command
{
    protected static $defaultName = 'docs:build-static';
    protected static $defaultDescription = 'Build static HTML site for GitHub Pages deployment';

    protected function configure(): void
    {
        $this
            ->setName('docs:build-static')
            ->setDescription('Build static HTML site for GitHub Pages deployment')
            ->addOption(
                'docs-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to rewritten markdown documentation directory',
                'output/docs'
            )
            ->addOption(
                'output-path',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Target output directory for static HTML',
                'dist'
            )
            ->addOption(
                'index-path',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Path to the symbols index JSON file (for the search dataset)',
                'storage/symbols_index.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Static Site Builder');

        $rawDocsPath = (string) $input->getOption('docs-path');
        $resolvedDocsPath = realpath($rawDocsPath);
        if ($resolvedDocsPath === false || !is_dir($resolvedDocsPath)) {
            $io->error("Documentation directory not found: {$rawDocsPath}. Please run 'docs:rewrite' first.");
            return Command::FAILURE;
        }
        $docsPath = $resolvedDocsPath;

        $outputPath = (string) $input->getOption('output-path');
        $symbolsIndexPath = (string) $input->getOption('index-path');
        if ($symbolsIndexPath === '') {
            $symbolsIndexPath = __DIR__ . '/../../storage/symbols_index.json';
        }

        if (!is_dir($outputPath) && !mkdir($outputPath, 0755, true) && !is_dir($outputPath)) {
            $io->error("Unable to create output directory: {$outputPath}");
            return Command::FAILURE;
        }

        try {
            $allDocs = glob($docsPath . '/*.md');
            if ($allDocs === false || empty($allDocs)) {
                $io->warning("No markdown files found in {$docsPath}");
                return Command::SUCCESS;
            }

            $converter = MarkdownPipeline::createConverter();

            $progressBar = new ProgressBar($output, count($allDocs));
            $progressBar->start();

            foreach ($allDocs as $docFile) {
                $slug = basename($docFile, '.md');
                $markdown = file_get_contents($docFile);
                if ($markdown === false) {
                    continue;
                }

                $sidebarHtml = SidebarBuilder::build($docsPath, $slug, true);
                $html = $this->renderPage($slug, $markdown, $converter, $sidebarHtml);
                
                $targetFile = $outputPath . '/' . $slug . '.html';
                file_put_contents($targetFile, $html);

                if ($slug === 'installation') {
                    file_put_contents($outputPath . '/index.html', $html);
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $output->writeln("\n");

            // Build API search index
            $this->buildSearchIndex($docsPath, $symbolsIndexPath, $outputPath);

            $io->success([
                "Static HTML build completed!",
                "Output written to: {$outputPath}",
                "You can now deploy the '{$outputPath}' directory to GitHub Pages."
            ]);

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->error("Build failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function renderPage(string $page, string $markdown, MarkdownConverter $converter, string $sidebarHtml): string
    {
        $normalized = MarkdownPipeline::normalizeMarkdown($markdown, '$1.html');
        $htmlContent = (string) $converter->convert($normalized);

        // Convert markdown first, then replace badges in HTML (shared with docs:serve).
        $htmlContent = BadgeRenderer::replaceInHtml($htmlContent);

        $toc = TocBuilder::build($htmlContent);

        $pageTitle = ucwords(str_replace('-', ' ', $page));

        return $this->getHtmlTemplate($pageTitle, $sidebarHtml, $toc['html'], $toc['links']);
    }

    private function buildSidebar(string $docsPath, string $activePage = ''): string
    {
        return SidebarBuilder::build($docsPath, $activePage, true);
    }

    private function buildSearchIndex(string $docsPath, string $symbolsIndexPath, string $outputPath): void
    {
        $apiDir = $outputPath . '/api';
        if (!is_dir($apiDir) && !mkdir($apiDir, 0755, true) && !is_dir($apiDir)) {
            throw new \RuntimeException("Unable to create directory: {$apiDir}");
        }

        $symbolsData = SearchIndexBuilder::loadSymbolsData($symbolsIndexPath);
        $symbolPages = SearchIndexBuilder::loadSymbolPages($docsPath);
        $results = SearchIndexBuilder::build($docsPath, $symbolsData, true, $symbolPages);
        $json = json_encode($results);
        if ($json === false || file_put_contents($apiDir . '/search.json', $json) === false) {
            throw new \RuntimeException('Unable to write search index.');
        }
    }

    private function getHtmlTemplate(string $pageTitle, string $sidebarHtml, string $htmlContent, string $tocLinks): string
    {
        return Layout::render($pageTitle, $sidebarHtml, $htmlContent, $tocLinks, [
            'brandHref' => 'index.html',
            'searchDataUrl' => 'api/search.json',
        ]);
    }
}
