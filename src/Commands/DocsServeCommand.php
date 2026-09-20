<?php

declare(strict_types=1);

namespace LaravelProDocs\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DocsServeCommand extends Command
{
    protected static $defaultName = 'docs:serve';
    protected static $defaultDescription = 'Launch a local web preview server matching Laravel official docs design';

    protected function configure(): void
    {
        $this
            ->setName('docs:serve')
            ->setDescription('Launch a local web preview server matching Laravel official docs design')
            ->addOption(
                'docs-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'Path to rewritten markdown documentation directory',
                'output/docs'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Port to bind the preview server to',
                '8080'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_OPTIONAL,
                'Host address to bind to',
                '127.0.0.1'
            )
            ->addOption(
                'index-path',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Path to the symbols index JSON file (for search)',
                'storage/symbols_index.json'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Local Documentation Viewer');

        $rawDocsPath = (string) $input->getOption('docs-path');
        $resolvedDocsPath = realpath($rawDocsPath);
        if ($resolvedDocsPath === false || !is_dir($resolvedDocsPath)) {
            $io->error("Documentation directory not found: {$rawDocsPath}. Please run 'docs:rewrite' first.");
            return Command::FAILURE;
        }
        $docsPath = $resolvedDocsPath;

        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $serverUrl = "http://{$host}:{$port}";

        $indexPath = (string) $input->getOption('index-path');
        if ($indexPath === '') {
            $indexPath = 'storage/symbols_index.json';
        }

        $routerFile = sys_get_temp_dir() . '/laravel_docs_preview_router_' . substr(md5($docsPath), 0, 8) . '.php';
        $routerCode = $this->generateRouterCode($docsPath, $indexPath);
        if (file_put_contents($routerFile, $routerCode) === false) {
            $io->error("Unable to write preview router to: {$routerFile}");
            return Command::FAILURE;
        }

        $io->success([
            "Official-Style Laravel Documentation preview server is live!",
            "Browse documentation at: {$serverUrl}",
            "Serving from: " . $docsPath,
            "Press Ctrl+C to stop the server.",
        ]);

        $cmd = sprintf(
            'php -S %s:%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($routerFile)
        );

        passthru($cmd);

        return Command::SUCCESS;
    }

    private function generateRouterCode(string $docsPath, ?string $symbolsIndexPath = null): string
    {
        $autoloadPath = realpath(__DIR__ . '/../../vendor/autoload.php');
        if ($symbolsIndexPath === null || $symbolsIndexPath === '') {
            $symbolsIndexPath = realpath(__DIR__ . '/../../storage/symbols_index.json');
        }
        // Use var_export so paths with quotes/spaces/newlines stay valid PHP.
        $exportedDocsPath = var_export($docsPath, true);
        $exportedAutoloadPath = var_export((string) $autoloadPath, true);
        $exportedSymbolsIndexPath = var_export((string) $symbolsIndexPath, true);

        $template = <<<'PHP'
<?php

require_once ###AUTOLOAD_PATH###;

use LaravelProDocs\Support\Layout;
use LaravelProDocs\Support\MarkdownPipeline;
use LaravelProDocs\Support\SearchIndexBuilder;
use LaravelProDocs\Support\SidebarBuilder;
use LaravelProDocs\Support\TocBuilder;

$docsPath = ###DOCS_PATH###;
$symbolsIndexPath = ###SYMBOLS_INDEX_PATH###;

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestUri) || $requestUri === '') {
    $requestUri = '/';
}

// Shared search dataset (same builder as docs:build-static).
if ($requestUri === '/api/search.json') {
    header('Content-Type: application/json');
    $symbolsData = SearchIndexBuilder::loadSymbolsData($symbolsIndexPath);
    $symbolPages = SearchIndexBuilder::loadSymbolPages($docsPath);
    echo json_encode(SearchIndexBuilder::build($docsPath, $symbolsData, false, $symbolPages));
    exit;
}

$page = trim($requestUri, '/');
if ($page === '' || $page === 'index.php' || $page === 'documentation') {
    $page = 'installation';
}

$stripped = preg_replace('/\\.html$/', '', $page);
$page = is_string($stripped) ? $stripped : $page;
// Restrict to safe slug characters to avoid directory traversal.
$page = preg_replace('/[^a-zA-Z0-9_-]/', '', $page);
if (!is_string($page) || $page === '') {
    $page = 'installation';
}
$filePath = $docsPath . '/' . $page . '.md';

if (!is_file($filePath)) {
    header("Location: /installation");
    exit;
}

$markdown = file_get_contents($filePath);
if (!is_string($markdown)) {
    http_response_code(500);
    echo 'Unable to read documentation file.';
    exit;
}

// Shared pipeline: normalize, convert, then replace badges (same as docs:build-static).
$converter = MarkdownPipeline::createConverter();
$htmlContent = MarkdownPipeline::toHtml($markdown, $converter, '/$1');

$toc = TocBuilder::build($htmlContent);
$sidebarHtml = SidebarBuilder::build($docsPath, $page, false);
$pageTitle = ucwords(str_replace('-', ' ', $page));

echo Layout::render($pageTitle, $sidebarHtml, $toc['html'], $toc['links'], [
    'brandHref' => '/',
    'searchDataUrl' => '/api/search.json',
]);
PHP;

        $code = str_replace(
            ['###AUTOLOAD_PATH###', '###DOCS_PATH###', '###SYMBOLS_INDEX_PATH###'],
            [$exportedAutoloadPath, $exportedDocsPath, $exportedSymbolsIndexPath],
            $template
        );

        return $code;
    }
}
