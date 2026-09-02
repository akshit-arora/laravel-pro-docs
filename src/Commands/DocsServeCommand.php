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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Local Documentation Viewer');

        $docsPath = (string) $input->getOption('docs-path');
        if (!is_dir($docsPath)) {
            $io->error("Documentation directory not found: {$docsPath}. Please run 'docs:rewrite' first.");
            return Command::FAILURE;
        }

        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $serverUrl = "http://{$host}:{$port}";

        $routerFile = sys_get_temp_dir() . '/laravel_docs_preview_router.php';
        $routerCode = $this->generateRouterCode(realpath($docsPath));
        file_put_contents($routerFile, $routerCode);

        $io->success([
            "Official-Style Laravel Documentation preview server is live!",
            "Browse documentation at: {$serverUrl}",
            "Serving from: " . realpath($docsPath),
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

    private function generateRouterCode(string $docsPath): string
    {
        $autoloadPath = realpath(__DIR__ . '/../../vendor/autoload.php');
        $symbolsIndexPath = realpath(__DIR__ . '/../../storage/symbols_index.json');
        $escapedDocsPath = addslashes($docsPath);
        $escapedAutoloadPath = addslashes((string) $autoloadPath);
        $escapedSymbolsIndexPath = addslashes((string) $symbolsIndexPath);

        $template = <<<'PHP'
<?php

require_once '###AUTOLOAD_PATH###';

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

$docsPath = '###DOCS_PATH###';
$symbolsIndexPath = '###SYMBOLS_INDEX_PATH###';

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API endpoint for search
if ($requestUri === '/api/search.json') {
    header('Content-Type: application/json');
    $results = [];
    $allDocs = glob($docsPath . '/*.md');
    foreach ($allDocs as $docFile) {
        $slug = basename($docFile, '.md');
        if ($slug === 'documentation') continue;
        $title = ucwords(str_replace('-', ' ', $slug));
        $results[] = [
            'type' => 'page',
            'title' => $title,
            'url' => '/' . $slug,
        ];
    }
    if (file_exists($symbolsIndexPath)) {
        $symbolsData = json_decode((string) file_get_contents($symbolsIndexPath), true);
        if (is_array($symbolsData)) {
            foreach ($symbolsData as $symbol => $meta) {
                if (str_starts_with($symbol, 'page:') || str_starts_with($symbol, 'header:')) continue;
                // Exclude verbose FQCNs (e.g. Illuminate\Support\...) to keep search dataset compact and comprehensive across A-Z
                if (str_contains($symbol, '\\')) continue;

                $results[] = [
                    'type' => 'symbol',
                    'title' => $symbol,
                    'version' => $meta['version'] ?? '',
                    'pr' => $meta['pr'] ?? null,
                    'pr_url' => $meta['pr_url'] ?? null,
                    'api_url' => $meta['api_url'] ?? null,
                ];
            }
        }
    }
    echo json_encode($results);
    exit;
}

$page = trim($requestUri, '/');
if ($page === '' || $page === 'index.php' || $page === 'documentation') {
    $page = 'installation';
}

$page = preg_replace('/\.html$/', '', $page);
$filePath = $docsPath . '/' . $page . '.md';

if (!file_exists($filePath)) {
    header("Location: /installation");
    exit;
}

$markdown = file_get_contents($filePath);

// Replace {{version}} in markdown links (/docs/{{version}}/foo -> /foo)
$markdown = preg_replace('/\/docs\/\{\{version\}\}\/([a-zA-Z0-9_-]+)/', '/$1', $markdown);

// Convert <x-since> tags to official-style Laravel badges (Version/PR + API Docs)
$markdown = preg_replace_callback(
    '/<x-since\s+([^>]+)\/>/',
    function ($matches) {
        $attrs = $matches[1];
        $v = preg_match('/v="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : '';
        $pr = preg_match('/pr="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;
        $url = preg_match('/url="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;
        $api = preg_match('/api="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;

        $badgeHtml = '';
        if ($pr !== null && $url !== null) {
            $badgeHtml .= sprintf(
                '<a href="%s" target="_blank" class="since-badge since-pr" title="Introduced in Laravel %s via PR #%s"><span>%s</span><span class="pr-pill">#%s ↗</span></a>',
                $url, $v, $pr, $v, $pr
            );
        } elseif ($url !== null) {
            $badgeHtml .= sprintf(
                '<a href="%s" target="_blank" class="since-badge" title="Introduced in Laravel %s"><span>%s ↗</span></a>',
                $url, $v, $v
            );
        } else {
            $badgeHtml .= sprintf('<span class="since-badge" title="Introduced in Laravel %s">%s</span>', $v, $v);
        }

        if ($api !== null) {
            $isSource = str_contains($api, 'github.com');
            $label = $isSource ? 'Source ↗' : 'API ↗';
            $title = $isSource ? 'View implementation source code on GitHub' : 'View Laravel API documentation & types';
            $badgeHtml .= sprintf(
                ' <a href="%s" target="_blank" class="since-badge since-api" title="%s"><span>%s</span></a>',
                $api, $title, $label
            );
        }

        return ' ' . $badgeHtml;
    },
    $markdown
);

// Style callouts / blockquotes (> **Note** or > [!NOTE])
$markdown = preg_replace('/^>\s*\[!NOTE\]\s*/m', '> **Note:** ', $markdown);
$markdown = preg_replace('/^>\s*\[!WARNING\]\s*/m', '> **Warning:** ', $markdown);

$environment = new Environment(['html_input' => 'allow']);
$environment->addExtension(new CommonMarkCoreExtension());
$environment->addExtension(new TableExtension());
$converter = new MarkdownConverter($environment);

$htmlContent = (string) $converter->convert($markdown);

// Extract on-this-page Table of Contents (H2 & H3)
preg_match_all('/<h([23])[^>]*>(.*?)<\/h\1>/i', $htmlContent, $tocMatches, PREG_SET_ORDER);
$tocLinks = '';
foreach ($tocMatches as $match) {
    $level = $match[1];
    $rawHeading = strip_tags($match[2]);
    $anchor = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $rawHeading), '-'));
    $indentClass = $level === '3' ? 'toc-h3' : 'toc-h2';
    $tocLinks .= sprintf('<li class="%s"><a href="#%s">%s</a></li>', $indentClass, $anchor, htmlspecialchars($rawHeading));
}

// Add IDs to heading tags for anchor jumping
$htmlContent = preg_replace_callback('/<h([23456])([^>]*)>(.*?)<\/h\1>/i', function ($m) {
    $level = $m[1];
    $attrs = $m[2];
    $inner = $m[3];
    $anchor = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', strip_tags($inner)), '-'));
    if (!str_contains($attrs, 'id=')) {
        $attrs .= ' id="' . $anchor . '"';
    }
    return "<h{$level}{$attrs}><a href=\"#{$anchor}\" class=\"heading-anchor\">#</a> {$inner}</h{$level}>";
}, $htmlContent);

// Build structured Sidebar Navigation from documentation.md
$docIndexFile = $docsPath . '/documentation.md';
$sidebarHtml = '';

if (file_exists($docIndexFile)) {
    $docIndexLines = file($docIndexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $currentCategory = null;
    
    foreach ($docIndexLines as $line) {
        if (preg_match('/^-\s*##\s*(.*)$/', $line, $catMatch)) {
            if ($currentCategory !== null) {
                $sidebarHtml .= '</ul></div>';
            }
            $currentCategory = trim($catMatch[1]);
            $sidebarHtml .= sprintf('<div class="nav-group"><div class="nav-group-title">%s</div><ul class="nav-links">', htmlspecialchars($currentCategory));
        } elseif (preg_match('/\[([^\]]+)\]\(\/docs\/\{\{version\}\}\/([^\)]+)\)/', $line, $linkMatch)) {
            $linkTitle = $linkMatch[1];
            $linkSlug = $linkMatch[2];
            $isActive = ($linkSlug === $page);
            $sidebarHtml .= sprintf(
                '<li><a href="/%s" class="nav-item %s">%s</a></li>',
                $linkSlug,
                $isActive ? 'active' : '',
                htmlspecialchars($linkTitle)
            );
        }
    }
    if ($currentCategory !== null) {
        $sidebarHtml .= '</ul></div>';
    }
} else {
    // Fallback alphabetic
    $allDocs = glob($docsPath . '/*.md');
    $sidebarHtml = '<div class="nav-group"><div class="nav-group-title">Documentation</div><ul class="nav-links">';
    foreach ($allDocs as $docFile) {
        $slug = basename($docFile, '.md');
        $title = ucwords(str_replace('-', ' ', $slug));
        $isActive = ($slug === $page);
        $sidebarHtml .= sprintf('<li><a href="/%s" class="nav-item %s">%s</a></li>', $slug, $isActive ? 'active' : '', htmlspecialchars($title));
    }
    $sidebarHtml .= '</ul></div>';
}

$pageTitle = ucwords(str_replace('-', ' ', $page));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Laravel Pro Documentation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --red-500: #FF2D20;
            --red-600: #E02417;
            --red-50: #FEF2F2;
            --red-100: #FEE2E2;
            --red-900: #7F1D1D;
            
            --bg: #FFFFFF;
            --bg-secondary: #FAFAFA;
            --sidebar-bg: #F8FAFC;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --text-dim: #94A3B8;
            --border: #E2E8F0;
            --border-light: #F1F5F9;
            --code-bg: #0F172A;
            --code-inline-bg: #F1F5F9;
            --code-inline-text: #E11D48;
            --callout-bg: #F8FAFC;
            --callout-border: #CBD5E1;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #090D16;
                --bg-secondary: #0F172A;
                --sidebar-bg: #060911;
                --text-main: #F8FAFC;
                --text-muted: #94A3B8;
                --text-dim: #64748B;
                --border: #1E293B;
                --border-light: #131C2E;
                --code-bg: #030712;
                --code-inline-bg: #1E293B;
                --code-inline-text: #FB7185;
                --callout-bg: #0F172A;
                --callout-border: #334155;
            }
        }

        [data-theme="light"] {
            --bg: #FFFFFF;
            --bg-secondary: #FAFAFA;
            --sidebar-bg: #F8FAFC;
            --text-main: #0F172A;
            --text-muted: #64748B;
            --text-dim: #94A3B8;
            --border: #E2E8F0;
            --border-light: #F1F5F9;
            --code-bg: #0F172A;
            --code-inline-bg: #F1F5F9;
            --code-inline-text: #E11D48;
            --callout-bg: #F8FAFC;
            --callout-border: #CBD5E1;
        }

        [data-theme="dark"] {
            --bg: #090D16;
            --bg-secondary: #0F172A;
            --sidebar-bg: #060911;
            --text-main: #F8FAFC;
            --text-muted: #94A3B8;
            --text-dim: #64748B;
            --border: #1E293B;
            --border-light: #131C2E;
            --code-bg: #030712;
            --code-inline-bg: #1E293B;
            --code-inline-text: #FB7185;
            --callout-bg: #0F172A;
            --callout-border: #334155;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Header */
        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            backdrop-filter: blur(8px);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
        }

        .brand-text {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .brand-badge {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 9999px;
            background: var(--red-50);
            color: var(--red-600);
            border: 1px solid rgba(255, 45, 32, 0.2);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .search-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 14px;
            color: var(--text-muted);
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .search-btn:hover {
            border-color: var(--red-500);
            color: var(--text-main);
        }

        .search-shortcut {
            background: var(--border);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
        }

        .theme-toggle-btn {
            background: none;
            border: 1px solid var(--border);
            border-radius: 8px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .theme-toggle-btn:hover {
            color: var(--text-main);
            border-color: var(--text-dim);
        }

        /* Layout */
        .layout-container {
            display: flex;
            flex: 1;
            max-width: 1560px;
            margin: 0 auto;
            width: 100%;
        }

        /* Left Navigation Sidebar */
        aside.left-sidebar {
            width: 290px;
            flex-shrink: 0;
            height: calc(100vh - 64px);
            position: sticky;
            top: 64px;
            overflow-y: auto;
            border-right: 1px solid var(--border);
            padding: 28px 20px 48px 20px;
            background: var(--sidebar-bg);
        }

        .nav-group {
            margin-bottom: 24px;
        }

        .nav-group-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            margin-bottom: 8px;
            padding-left: 10px;
        }

        .nav-links {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-item {
            display: block;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 0.88rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.12s ease;
            font-weight: 500;
            line-height: 1.4;
        }

        .nav-item:hover {
            color: var(--text-main);
            background: rgba(255, 45, 32, 0.07);
        }

        .nav-item.active {
            color: var(--red-500);
            background: rgba(255, 45, 32, 0.1);
            font-weight: 600;
        }

        /* Main Content Container */
        main.main-content {
            flex: 1;
            min-width: 0;
            padding: 44px 56px 80px 56px;
            max-width: 920px;
        }

        /* Article Typography */
        article h1 {
            font-size: 2.4rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 24px 0;
            line-height: 1.2;
            color: var(--text-main);
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        article h2 {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 48px 0 16px 0;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
            position: relative;
        }

        article h3 {
            font-size: 1.28rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            margin: 36px 0 14px 0;
            color: var(--text-main);
            position: relative;
        }

        article h4 {
            font-size: 1.05rem;
            font-weight: 600;
            margin: 28px 0 12px 0;
            color: var(--text-main);
            position: relative;
        }

        .heading-anchor {
            position: absolute;
            left: -22px;
            top: 2px;
            color: var(--red-500);
            text-decoration: none;
            opacity: 0;
            font-weight: 400;
            transition: opacity 0.15s ease;
        }

        h2:hover .heading-anchor,
        h3:hover .heading-anchor,
        h4:hover .heading-anchor {
            opacity: 0.8;
        }

        article p {
            line-height: 1.75;
            font-size: 1rem;
            color: var(--text-main);
            margin: 16px 0;
        }

        article ul, article ol {
            padding-left: 24px;
            line-height: 1.75;
            margin: 16px 0;
        }

        article li {
            margin-bottom: 6px;
        }

        article a {
            color: var(--red-500);
            text-decoration: none;
            font-weight: 500;
        }

        article a:hover {
            text-decoration: underline;
        }

        /* Inline Code */
        code:not(pre code) {
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
            background: var(--code-inline-bg);
            color: var(--code-inline-text);
            padding: 3px 6px;
            border-radius: 5px;
            font-size: 0.88em;
            font-weight: 500;
        }

        /* Code Blocks */
        pre {
            background: var(--code-bg);
            color: #F8FAFC;
            padding: 20px 24px;
            border-radius: 10px;
            overflow-x: auto;
            font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, monospace;
            font-size: 0.88rem;
            line-height: 1.65;
            margin: 24px 0;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            position: relative;
        }

        pre code {
            background: none !important;
            padding: 0 !important;
            color: inherit !important;
            font-size: inherit;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 28px 0;
            font-size: 0.92rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--sidebar-bg);
            font-weight: 600;
            color: var(--text-muted);
        }

        /* Blockquotes / Callouts */
        blockquote {
            margin: 24px 0;
            padding: 16px 20px;
            background: var(--callout-bg);
            border-left: 4px solid var(--red-500);
            border-radius: 0 8px 8px 0;
            color: var(--text-main);
        }

        blockquote p {
            margin: 0;
            font-size: 0.94rem;
        }

        /* Laravel Since Badges */
        .since-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 9999px;
            background: #E2E8F0;
            color: #334155;
            vertical-align: middle;
            text-decoration: none !important;
            margin-left: 6px;
            line-height: 1.5;
            letter-spacing: 0.01em;
            transition: all 0.15s ease;
        }

        .since-pr {
            background: var(--red-50);
            color: var(--red-600);
            border: 1px solid rgba(255, 45, 32, 0.25);
        }

        .since-pr:hover {
            background: var(--red-100);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(255, 45, 32, 0.15);
        }

        .since-api {
            background: var(--bg-secondary);
            color: var(--text-muted);
            border: 1px solid var(--border);
            margin-left: 4px;
        }

        .since-api:hover {
            color: var(--text);
            border-color: var(--text-muted);
            transform: translateY(-1px);
        }

        .pr-pill {
            font-weight: 700;
            background: var(--red-600);
            color: #FFFFFF;
            padding: 0px 5px;
            border-radius: 9999px;
            font-size: 0.68rem;
        }

        /* Right Sidebar: On This Page */
        aside.right-sidebar {
            width: 250px;
            flex-shrink: 0;
            height: calc(100vh - 64px);
            position: sticky;
            top: 64px;
            overflow-y: auto;
            padding: 44px 16px 48px 16px;
        }

        .toc-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .toc-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .toc-list li a {
            display: block;
            padding: 5px 0;
            color: var(--text-muted);
            font-size: 0.83rem;
            text-decoration: none;
            line-height: 1.4;
            transition: color 0.12s ease;
        }

        .toc-list li a:hover {
            color: var(--red-500);
        }

        .toc-h3 {
            padding-left: 12px;
        }

        /* Search Modal Overlay */
        .search-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 100;
            align-items: flex-start;
            justify-content: center;
            padding-top: 100px;
        }

        .search-modal {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            width: 620px;
            max-width: 90vw;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }

        .search-input-wrapper {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }

        .search-input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            font-size: 1.05rem;
            color: var(--text-main);
            font-family: inherit;
        }

        .search-results {
            max-height: 400px;
            overflow-y: auto;
            padding: 8px 0;
        }

        .search-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            color: inherit;
            text-decoration: none;
            font-size: 0.92rem;
            transition: background 0.1s ease;
        }

        .search-item:hover, .search-item.selected {
            background: rgba(255, 45, 32, 0.08);
            color: var(--red-500);
        }
    </style>
</head>
<body>
    <header>
        <a href="/" class="header-brand">
            <div class="brand-text">
                Laravel <span style="font-weight:400; color:var(--text-muted);">Docs</span>
                <span class="brand-badge">Version & PR Enhanced</span>
            </div>
        </a>

        <div class="header-actions">
            <button class="search-btn" onclick="openSearch()">
                <span>🔍 Search documentation or symbols...</span>
                <span class="search-shortcut">⌘K</span>
            </button>
            <button class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Dark/Light Mode">
                🌓
            </button>
        </div>
    </header>

    <div class="layout-container">
        <aside class="left-sidebar">
            <?= $sidebarHtml ?>
        </aside>

        <main class="main-content">
            <article>
                <?= $htmlContent ?>
            </article>
            
            <footer style="margin-top: 60px; padding-top: 24px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 12px; font-size: 0.9rem; color: var(--text-muted);">
                <div>
                    <strong>Laravel Pro Docs</strong> &copy; <?= date('Y') ?> 
                    <a href="https://github.com/akshit-arora" target="_blank" style="color: var(--text-main); font-weight: 500; text-decoration: none;">Akshit Arora</a>.
                </div>
                <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                    <a href="https://github.com/akshit-arora/laravel-pro-docs" target="_blank" style="color: var(--red-500); text-decoration: none;">GitHub Repository</a>
                    <a href="https://github.com/akshit-arora/laravel-pro-docs/issues" target="_blank" style="color: var(--red-500); text-decoration: none;">Submit Issue / PR</a>
                    <a href="https://laravel.com/docs" target="_blank" style="color: var(--red-500); text-decoration: none;">Official Laravel Docs</a>
                    <a href="https://x.com/akshitarora0907" target="_blank" style="color: var(--red-500); text-decoration: none;">X (Twitter) @akshitarora0907</a>
                </div>
            </footer>
        </main>

        <?php if (!empty($tocLinks)): ?>
        <aside class="right-sidebar">
            <div class="toc-title">On This Page</div>
            <ul class="toc-list">
                <?= $tocLinks ?>
            </ul>
        </aside>
        <?php endif; ?>
    </div>

    <!-- Quick Search Modal -->
    <div id="searchModal" class="search-modal-backdrop" onclick="closeSearchOnBackdrop(event)">
        <div class="search-modal">
            <div class="search-input-wrapper">
                <input type="text" id="searchInput" class="search-input" placeholder="Search pages (e.g. Cache, Eloquent) or symbols (e.g. Number::currency)..." oninput="handleSearch(this.value)">
            </div>
            <div id="searchResults" class="search-results"></div>
        </div>
    </div>

    <script>
        let searchData = [];
        fetch('/api/search.json').then(r => r.json()).then(d => searchData = d).catch(() => {});

        function openSearch() {
            document.getElementById('searchModal').style.display = 'flex';
            document.getElementById('searchInput').focus();
        }

        function closeSearch() {
            document.getElementById('searchModal').style.display = 'none';
        }

        function closeSearchOnBackdrop(e) {
            if (e.target.id === 'searchModal') closeSearch();
        }

        window.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                openSearch();
            }
            if (e.key === 'Escape') {
                closeSearch();
            }
        });

        function handleSearch(q) {
            const container = document.getElementById('searchResults');
            if (!q || q.trim() === '') {
                container.innerHTML = '';
                return;
            }
            const query = q.toLowerCase();
            const filtered = searchData.filter(item => item.title.toLowerCase().includes(query)).slice(0, 15);
            
            container.innerHTML = filtered.map(item => {
                if (item.type === 'page') {
                    return `<a href="${item.url}" class="search-item">
                        <span>📄 ${item.title}</span>
                        <span style="font-size:0.75rem; color:var(--text-muted);">Documentation Page</span>
                    </a>`;
                } else {
                    const isSource = item.api_url && item.api_url.includes('github.com');
                    const apiLabel = isSource ? 'Source ↗' : 'API ↗';
                    const apiTitle = isSource ? 'View implementation source code on GitHub' : 'View API documentation';
                    const apiLink = item.api_url ? `<a href="${item.api_url}" target="_blank" class="since-badge since-api" title="${apiTitle}">${apiLabel}</a>` : '';
                    return `<div class="search-item" style="cursor:default;">
                        <span>⚡ <code>${item.title}</code></span>
                        <div style="display:flex; align-items:center; gap:4px;">
                            ${prLink}
                            ${apiLink}
                        </div>
                    </div>`;
                }
            }).join('');
        }

        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme');
            const target = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', target);
            localStorage.setItem('laravel_docs_theme', target);
        }

        const savedTheme = localStorage.getItem('laravel_docs_theme');
        if (savedTheme) {
            document.documentElement.setAttribute('data-theme', savedTheme);
        }
    </script>
</body>
</html>
PHP;

        $code = str_replace(
            ['###AUTOLOAD_PATH###', '###DOCS_PATH###', '###SYMBOLS_INDEX_PATH###'],
            [$escapedAutoloadPath, $escapedDocsPath, $escapedSymbolsIndexPath],
            $template
        );

        return $code;
    }
}
