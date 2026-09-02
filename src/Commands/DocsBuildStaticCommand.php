<?php

declare(strict_types=1);

namespace LaravelProDocs\Commands;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Laravel Pro Docs: Static Site Builder');

        $docsPath = (string) $input->getOption('docs-path');
        if (!is_dir($docsPath)) {
            $io->error("Documentation directory not found: {$docsPath}. Please run 'docs:rewrite' first.");
            return Command::FAILURE;
        }

        $outputPath = (string) $input->getOption('output-path');
        $symbolsIndexPath = __DIR__ . '/../../storage/symbols_index.json';

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

            // Build sidebar HTML
            $sidebarHtml = $this->buildSidebar($docsPath);

            $environment = new Environment(['html_input' => 'allow']);
            $environment->addExtension(new CommonMarkCoreExtension());
            $environment->addExtension(new TableExtension());
            $converter = new MarkdownConverter($environment);

            $progressBar = new ProgressBar($output, count($allDocs));
            $progressBar->start();

            foreach ($allDocs as $docFile) {
                $slug = basename($docFile, '.md');
                $markdown = file_get_contents($docFile);
                if ($markdown === false) continue;

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
        // Highlight active sidebar item
        $sidebarHtml = preg_replace('/class="nav-item active"/', 'class="nav-item"', $sidebarHtml);
        $sidebarHtml = preg_replace('/href="' . preg_quote($page, '/') . '\.html" class="nav-item"/', 'href="' . $page . '.html" class="nav-item active"', $sidebarHtml);

        $markdown = preg_replace('/\/docs\/\{\{version\}\}\/([a-zA-Z0-9_-]+)/', '$1.html', $markdown);

        $markdown = preg_replace_callback('/<x-since\s+([^>]+)\/>/', function ($matches) {
            $attrs = $matches[1];
            $v = preg_match('/v="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : '';
            $pr = preg_match('/pr="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;
            $url = preg_match('/url="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;
            $api = preg_match('/api="([^"]+)"/', $attrs, $m) ? htmlspecialchars($m[1]) : null;

            $badgeHtml = '';
            if ($pr !== null && $url !== null) {
                $badgeHtml .= sprintf('<a href="%s" target="_blank" class="since-badge since-pr" title="Introduced in Laravel %s via PR #%s"><span>%s</span><span class="pr-pill">#%s ↗</span></a>', $url, $v, $pr, $v, $pr);
            } elseif ($url !== null) {
                $badgeHtml .= sprintf('<a href="%s" target="_blank" class="since-badge" title="Introduced in Laravel %s"><span>%s ↗</span></a>', $url, $v, $v);
            } else {
                $badgeHtml .= sprintf('<span class="since-badge" title="Introduced in Laravel %s">%s</span>', $v, $v);
            }
            if ($api !== null) {
                $isSource = str_contains($api, 'github.com');
                $label = $isSource ? 'Source ↗' : 'API ↗';
                $badgeHtml .= sprintf(' <a href="%s" target="_blank" class="since-badge since-api"><span>%s</span></a>', $api, $label);
            }
            return ' ' . $badgeHtml;
        }, $markdown);

        $markdown = preg_replace('/^>\s*\[!NOTE\]\s*/m', '> **Note:** ', $markdown);
        $markdown = preg_replace('/^>\s*\[!WARNING\]\s*/m', '> **Warning:** ', $markdown);

        $htmlContent = (string) $converter->convert($markdown);

        preg_match_all('/<h([23])[^>]*>(.*?)<\/h\1>/i', $htmlContent, $tocMatches, PREG_SET_ORDER);
        $tocLinks = '';
        foreach ($tocMatches as $match) {
            $level = $match[1];
            $rawHeading = strip_tags($match[2]);
            $anchor = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $rawHeading), '-'));
            $indentClass = $level === '3' ? 'toc-h3' : 'toc-h2';
            $tocLinks .= sprintf('<li class="%s"><a href="#%s">%s</a></li>', $indentClass, $anchor, htmlspecialchars($rawHeading));
        }

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

        $pageTitle = ucwords(str_replace('-', ' ', $page));

        return $this->getHtmlTemplate($pageTitle, $sidebarHtml, $htmlContent, $tocLinks);
    }

    private function buildSidebar(string $docsPath): string
    {
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
                    $sidebarHtml .= sprintf('<li><a href="%s.html" class="nav-item">%s</a></li>', $linkMatch[2], htmlspecialchars($linkMatch[1]));
                }
            }
            if ($currentCategory !== null) {
                $sidebarHtml .= '</ul></div>';
            }
        }
        return $sidebarHtml;
    }

    private function buildSearchIndex(string $docsPath, string $symbolsIndexPath, string $outputPath): void
    {
        $apiDir = $outputPath . '/api';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0755, true);
        }

        $results = [];
        $allDocs = glob($docsPath . '/*.md');
        foreach ($allDocs as $docFile) {
            $slug = basename($docFile, '.md');
            if ($slug === 'documentation') continue;
            $results[] = [
                'type' => 'page',
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'url' => $slug . '.html',
            ];
        }
        if (file_exists($symbolsIndexPath)) {
            $symbolsData = json_decode((string) file_get_contents($symbolsIndexPath), true);
            if (is_array($symbolsData)) {
                foreach ($symbolsData as $symbol => $meta) {
                    if (str_starts_with($symbol, 'page:') || str_starts_with($symbol, 'header:') || str_contains($symbol, '\\')) continue;
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
        file_put_contents($apiDir . '/search.json', json_encode($results));
    }

    private function getHtmlTemplate(string $pageTitle, string $sidebarHtml, string $htmlContent, string $tocLinks): string
    {
        $year = date('Y');
        $css = <<<'CSS'
:root { --red-500: #FF2D20; --red-600: #E02417; --red-50: #FEF2F2; --red-100: #FEE2E2; --red-900: #7F1D1D; --bg: #FFFFFF; --bg-secondary: #FAFAFA; --sidebar-bg: #F8FAFC; --text-main: #0F172A; --text-muted: #64748B; --text-dim: #94A3B8; --border: #E2E8F0; --border-light: #F1F5F9; --code-bg: #0F172A; --code-inline-bg: #F1F5F9; --code-inline-text: #E11D48; --callout-bg: #F8FAFC; --callout-border: #CBD5E1; }
@media (prefers-color-scheme: dark) { :root { --bg: #090D16; --bg-secondary: #0F172A; --sidebar-bg: #060911; --text-main: #F8FAFC; --text-muted: #94A3B8; --text-dim: #64748B; --border: #1E293B; --border-light: #131C2E; --code-bg: #030712; --code-inline-bg: #1E293B; --code-inline-text: #FB7185; --callout-bg: #0F172A; --callout-border: #334155; } }
[data-theme="light"] { --bg: #FFFFFF; --bg-secondary: #FAFAFA; --sidebar-bg: #F8FAFC; --text-main: #0F172A; --text-muted: #64748B; --text-dim: #94A3B8; --border: #E2E8F0; --border-light: #F1F5F9; --code-bg: #0F172A; --code-inline-bg: #F1F5F9; --code-inline-text: #E11D48; --callout-bg: #F8FAFC; --callout-border: #CBD5E1; }
[data-theme="dark"] { --bg: #090D16; --bg-secondary: #0F172A; --sidebar-bg: #060911; --text-main: #F8FAFC; --text-muted: #94A3B8; --text-dim: #64748B; --border: #1E293B; --border-light: #131C2E; --code-bg: #030712; --code-inline-bg: #1E293B; --code-inline-text: #FB7185; --callout-bg: #0F172A; --callout-border: #334155; }
* { box-sizing: border-box; } html { scroll-behavior: smooth; } body { margin: 0; font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); display: flex; flex-direction: column; min-height: 100vh; -webkit-font-smoothing: antialiased; }
header { position: sticky; top: 0; z-index: 50; background: var(--bg); border-bottom: 1px solid var(--border); height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; }
.header-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit; } .brand-text { font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 8px; } .brand-badge { font-size: 0.7rem; padding: 2px 7px; border-radius: 9999px; background: var(--red-50); color: var(--red-600); border: 1px solid rgba(255,45,32,0.2); }
.header-actions { display: flex; align-items: center; gap: 16px; } .search-btn { display: flex; align-items: center; gap: 10px; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 8px; padding: 6px 14px; color: var(--text-muted); cursor: pointer; } .theme-toggle-btn { background: none; border: 1px solid var(--border); border-radius: 8px; width: 36px; height: 36px; cursor: pointer; color: var(--text-muted); }
.layout-container { display: flex; flex: 1; max-width: 1560px; margin: 0 auto; width: 100%; }
aside.left-sidebar { width: 290px; flex-shrink: 0; height: calc(100vh - 64px); position: sticky; top: 64px; overflow-y: auto; border-right: 1px solid var(--border); padding: 28px 20px 48px 20px; background: var(--sidebar-bg); }
.nav-group-title { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 8px; padding-left: 10px; } .nav-links { list-style: none; margin: 0; padding: 0; margin-bottom: 24px; } .nav-item { display: block; padding: 7px 12px; border-radius: 6px; font-size: 0.88rem; color: var(--text-muted); text-decoration: none; } .nav-item.active { color: var(--red-500); background: rgba(255,45,32,0.1); font-weight: 600; }
main.main-content { flex: 1; padding: 44px 56px 80px 56px; max-width: 920px; }
article h1 { font-size: 2.4rem; font-weight: 800; margin: 0 0 24px 0; } article h2 { font-size: 1.6rem; border-bottom: 1px solid var(--border); padding-bottom: 12px; margin: 48px 0 16px 0; position: relative; } article h3 { font-size: 1.28rem; margin: 36px 0 14px 0; position: relative; }
.heading-anchor { position: absolute; left: -22px; top: 2px; color: var(--red-500); text-decoration: none; opacity: 0; } h2:hover .heading-anchor, h3:hover .heading-anchor { opacity: 0.8; }
article p { line-height: 1.75; margin: 16px 0; } article a { color: var(--red-500); text-decoration: none; font-weight: 500; } article ul { padding-left: 24px; line-height: 1.75; }
code { background: var(--code-inline-bg); color: var(--code-inline-text); padding: 3px 6px; border-radius: 5px; font-size: 0.88em; } pre { background: var(--code-bg); color: #F8FAFC; padding: 20px 24px; border-radius: 10px; overflow-x: auto; font-size: 0.88rem; line-height: 1.65; margin: 24px 0; } pre code { background: none; color: inherit; padding: 0; }
.since-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 0.72rem; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: #E2E8F0; color: #334155; text-decoration: none !important; margin-left: 6px; } .since-pr { background: var(--red-50); color: var(--red-600); border: 1px solid rgba(255,45,32,0.25); } .pr-pill { background: var(--red-600); color: #FFF; padding: 0 5px; border-radius: 9999px; font-size: 0.68rem; }
aside.right-sidebar { width: 250px; flex-shrink: 0; height: calc(100vh - 64px); position: sticky; top: 64px; overflow-y: auto; padding: 44px 16px 48px 16px; } .toc-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; } .toc-list { list-style: none; margin: 0; padding: 0; } .toc-list li a { display: block; padding: 5px 0; color: var(--text-muted); font-size: 0.83rem; text-decoration: none; }
.search-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 100; align-items: flex-start; justify-content: center; padding-top: 100px; } .search-modal { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; width: 620px; max-width: 90vw; } .search-input-wrapper { padding: 16px 20px; border-bottom: 1px solid var(--border); } .search-input { width: 100%; border: none; background: transparent; font-size: 1.05rem; color: var(--text-main); outline: none; } .search-results { max-height: 400px; overflow-y: auto; padding: 8px 0; } .search-item { display: flex; align-items: center; justify-content: space-between; padding: 10px 20px; color: inherit; text-decoration: none; font-size: 0.92rem; } .search-item:hover { background: rgba(255,45,32,0.08); }
CSS;

        $rightSidebar = !empty($tocLinks) ? "<aside class=\"right-sidebar\"><div class=\"toc-title\">On This Page</div><ul class=\"toc-list\">{$tocLinks}</ul></aside>" : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$pageTitle} - Laravel Pro Documentation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{$css}</style>
</head>
<body>
    <header>
        <a href="index.html" class="header-brand">
            <div class="brand-text">Laravel <span style="font-weight:400; color:var(--text-muted);">Docs</span> <span class="brand-badge">Version & PR Enhanced</span></div>
        </a>
        <div class="header-actions">
            <button class="search-btn" onclick="openSearch()"><span>🔍 Search...</span></button>
            <button class="theme-toggle-btn" onclick="toggleTheme()">🌓</button>
        </div>
    </header>
    <div class="layout-container">
        <aside class="left-sidebar">{$sidebarHtml}</aside>
        <main class="main-content">
            <article>{$htmlContent}</article>
            <footer style="margin-top: 60px; padding-top: 24px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 12px; font-size: 0.9rem; color: var(--text-muted);">
                <div>
                    <strong>Laravel Pro Docs</strong> &copy; {$year}
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
        {$rightSidebar}
    </div>
    <div id="searchModal" class="search-modal-backdrop" onclick="closeSearchOnBackdrop(event)">
        <div class="search-modal">
            <div class="search-input-wrapper"><input type="text" id="searchInput" class="search-input" placeholder="Search pages or symbols..." oninput="handleSearch(this.value)"></div>
            <div id="searchResults" class="search-results"></div>
        </div>
    </div>
    <script>
        let searchData = [];
        fetch('api/search.json').then(r => r.json()).then(d => searchData = d).catch(() => {});
        function openSearch() { document.getElementById('searchModal').style.display = 'flex'; document.getElementById('searchInput').focus(); }
        function closeSearch() { document.getElementById('searchModal').style.display = 'none'; }
        function closeSearchOnBackdrop(e) { if (e.target.id === 'searchModal') closeSearch(); }
        window.addEventListener('keydown', (e) => { if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); openSearch(); } if (e.key === 'Escape') closeSearch(); });
        function handleSearch(q) {
            const container = document.getElementById('searchResults');
            if (!q || q.trim() === '') { container.innerHTML = ''; return; }
            const query = q.toLowerCase();
            container.innerHTML = searchData.filter(i => i.title.toLowerCase().includes(query)).slice(0,15).map(item => {
                if (item.type === 'page') return `<a href="\${item.url}" class="search-item"><span>📄 \${item.title}</span></a>`;
                let prLink = item.pr ? `<a href="\${item.pr_url}" target="_blank" class="since-badge since-pr"><span>\${item.version}</span><span class="pr-pill">#\${item.pr} ↗</span></a>` : '';
                return `<div class="search-item" style="cursor:default;"><span>⚡ \${item.title}</span><div>\${prLink}</div></div>`;
            }).join('');
        }
        function toggleTheme() {
            const target = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', target);
            localStorage.setItem('laravel_docs_theme', target);
        }
        if (localStorage.getItem('laravel_docs_theme')) document.documentElement.setAttribute('data-theme', localStorage.getItem('laravel_docs_theme'));
    </script>
</body>
</html>
HTML;
    }
}
