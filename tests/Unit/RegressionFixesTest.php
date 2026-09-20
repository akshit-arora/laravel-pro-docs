<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Unit;

use LaravelProDocs\Commands\DocsServeCommand;
use LaravelProDocs\Git\PrExtractor;
use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Rewriter\MarkdownRewriter;
use LaravelProDocs\Rewriter\SymbolMatcher;
use PHPUnit\Framework\TestCase;

class RegressionFixesTest extends TestCase
{
    public function testGenericMethodNameDoesNotStealUnrelatedPr(): void
    {
        $extractor = new PrExtractor();
        $commits = [
            'Add README docs (#49000)',
            'Fix typo in readme (#49001)',
        ];

        // "add" is generic: must not match README commits for Context::add.
        $this->assertNull($extractor->findPrForSymbol($commits, 'Context::add'));
    }

    public function testAmbiguousFileCommitsDoNotReturnRandomPr(): void
    {
        $extractor = new PrExtractor();
        $commits = [
            '[10.x] Fix cache style (#49100)',
            '[10.x] Update validation message (#49200)',
        ];

        // Two different PRs touched the file: previously returned 49100 at random.
        $this->assertNull($extractor->findPrForSymbol($commits, 'SomeUnknownSymbol'));
    }

    public function testUnambiguousSinglePrFileHistoryStillMatches(): void
    {
        $extractor = new PrExtractor();
        $commits = [
            '[10.x] Add Number::currency helper (#49451)',
        ];

        $this->assertSame(49451, $extractor->findPrForSymbol($commits, 'Number::currency'));
    }

    public function testFullSymbolStillMatchesWhenMethodIsGeneric(): void
    {
        $extractor = new PrExtractor();
        $commits = [
            '[11.x] Add Context::add method (#50123)',
            'Unrelated docs tweak (#50000)',
        ];

        // Full symbol mention wins even though "add" alone is generic.
        $this->assertSame(50123, $extractor->findPrForSymbol($commits, 'Context::add'));
    }

    public function testTildeFencesAndIndentedCodeAreUntouched(): void
    {
        $registry = new SymbolRegistry();
        $registry->register('Number::currency', 'v10.38.0', 49451, 'https://github.com/laravel/framework/pull/49451');
        $rewriter = new MarkdownRewriter(new SymbolMatcher($registry));

        $input = <<<'MD'
Text `Number::currency()` here.

~~~php
$price = Number::currency(1000);
~~~

    Indented `Number::currency()` stays untouched.

More `Number::currency()` here.
MD;

        $rewritten = $rewriter->rewrite($input);

        // Outside fences: tagged.
        $this->assertStringContainsString('Text `Number::currency()` <x-since', $rewritten);
        $this->assertStringContainsString('More `Number::currency()` <x-since', $rewritten);
        // Inside tilde fence + indented block: untouched.
        $this->assertStringContainsString('$price = Number::currency(1000);', $rewritten);
        $this->assertStringNotContainsString('$price = Number::currency(1000); <x-since', $rewritten);
        $this->assertStringContainsString('    Indented `Number::currency()` stays untouched.', $rewritten);
    }

    public function testServeRouterUsesSharedPipeline(): void
    {
        $cmd = new DocsServeCommand();
        $ref = new \ReflectionMethod(DocsServeCommand::class, 'generateRouterCode');
        $ref->setAccessible(true);
        $code = $ref->invoke($cmd, sys_get_temp_dir());

        // Router must delegate to shared builders instead of carrying its own
        // 600-line copy of badges/sidebar/search (which had already drifted).
        $this->assertStringContainsString('MarkdownPipeline', $code);
        $this->assertStringContainsString('SearchIndexBuilder', $code);
        $this->assertStringContainsString('SidebarBuilder', $code);
        $this->assertStringContainsString('TocBuilder', $code);
        $this->assertStringContainsString('Layout::render', $code);
        $this->assertStringNotContainsString('TableExtension', $code);
        // No bare undefined `${prLink}` reference anymore (was a ReferenceError).
        $this->assertStringNotContainsString('${prLink}', $code);
        // Paths must be var_exported, not addslashes-concatenated.
        $this->assertStringContainsString('require_once', $code);
    }

    public function testSharedLayoutContainsWorkingSearch(): void
    {
        $html = \LaravelProDocs\Support\Layout::render('Cache', '<nav></nav>', '<p>Hi</p>', '', []);

        $this->assertStringContainsString('const prLink', $html);
        $this->assertStringContainsString('escapeHtml', $html);
        $this->assertStringContainsString('/api/search.json', $html);
        // Symbol rows must navigate to the docs page (data-url + goResult
        // handler), never straight to the API page.
        $this->assertStringContainsString('goResult', $html);
        $this->assertStringContainsString('data-url', $html);
        $this->assertStringContainsString('if (item.url)', $html);
        $this->assertStringContainsString('window.location.href = url', $html);
    }

    public function testRewriterHookRecordsSymbolPages(): void
    {
        $seen = [];
        $rewriter = new MarkdownRewriter(
            new SymbolMatcher($this->makeRegistry()),
            function (string $symbol, ?string $slug, ?string $anchor) use (&$seen): void {
                $seen[$symbol][] = [$slug, $anchor];
            }
        );

        $rewriter->rewrite('Use `Number::currency($amount)` here.', 'numbers');

        $this->assertSame([['numbers', null]], $seen['Number::currency'] ?? []);
    }

    public function testRewriterHookRecordsSectionAnchors(): void
    {
        $seen = [];
        $rewriter = new MarkdownRewriter(
            new SymbolMatcher($this->makeRegistry()),
            function (string $symbol, ?string $slug, ?string $anchor) use (&$seen): void {
                $seen[$symbol][] = [$slug, $anchor];
            }
        );

        $rewriter->rewrite(
            '## Formatting Numbers' . "\n\n" . 'Use `Number::currency($amount)` here.' . "\n\n" . '### Number::currency' . "\n\n" . 'Details.',
            'numbers'
        );

        $anchors = array_column($seen['Number::currency'] ?? [], 1);
        $this->assertContains('formatting-numbers', $anchors);
        $this->assertContains('number-currency', $anchors);
    }

    public function testRewriteTimeAnchorsMatchRenderTimeIds(): void
    {
        $seen = [];
        $rewriter = new MarkdownRewriter(
            new SymbolMatcher($this->makeRegistry()),
            function (string $symbol, ?string $slug, ?string $anchor) use (&$seen): void {
                $seen[$symbol] = $anchor;
            }
        );

        $markdown = '### Number::currency' . "\n\n" . 'Use `Number::currency($amount)`.' . "\n\n" . '#### `after()` {.collection-method}' . "\n";
        $rewritten = $rewriter->rewrite($markdown, 'numbers');

        $converter = \LaravelProDocs\Support\MarkdownPipeline::createConverter();
        $html = \LaravelProDocs\Support\MarkdownPipeline::toHtml($rewritten, $converter, '/$1');
        $toc = \LaravelProDocs\Support\TocBuilder::build($html);

        // Every recorded anchor must exist as a heading id in the rendered page.
        foreach ($seen as $symbol => $anchor) {
            $this->assertNotNull($anchor, "no anchor recorded for {$symbol}");
            $this->assertStringContainsString('id="' . $anchor . '"', $toc['html'], "anchor {$anchor} for {$symbol} missing from rendered HTML");
        }
        // Collection-method {.markers} are excluded from anchors on both sides
        // (the marker text itself still renders in the heading).
        $this->assertStringContainsString('id="after"', $toc['html']);
        $this->assertStringNotContainsString('id="after-collection-method"', $toc['html']);
    }

    public function testPipelineAllowsDocsStyleBlocksButStillEscapesScripts(): void
    {
        $converter = \LaravelProDocs\Support\MarkdownPipeline::createConverter();
        $html = (string) $converter->convert(
            "<style>\n    .collection-method-list > p {\n        columns: 10.8em 3;\n    }\n</style>\n\n<script>alert(1)</script>\n"
        );

        // Laravel's docs ship <style> blocks (e.g. 3-column method lists) that
        // must render as CSS, not escaped text.
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('columns: 10.8em 3;', $html);
        $this->assertStringNotContainsString('&lt;style>', $html);
        // Genuinely dangerous tags stay escaped.
        $this->assertStringContainsString('&lt;script>', $html);
    }

    public function testLayoutScopesBadgesAgainstDocsListStyles(): void
    {
        $html = \LaravelProDocs\Support\Layout::render('Collections', '<nav></nav>', '<p>Hi</p>', '', []);

        // The docs' own `.collection-method-list a { display: block; ... }`
        // rule (0-1-1) would turn every badge into a full-width block; this
        // higher-specificity override (0-2-1) keeps badges as inline pills.
        $this->assertStringContainsString('.collection-method-list a.since-badge', $html);
        $this->assertStringContainsString('display: inline-flex', $html);
    }

    public function testTocBuilderPromotesHeadingMarkersToClasses(): void
    {
        $html = '<h4><code>after()</code> {.collection-method .first-collection-method}</h4>';
        $toc = \LaravelProDocs\Support\TocBuilder::build($html);

        // Marker text must not render literally; it becomes heading classes.
        $this->assertStringNotContainsString('{.collection-method', $toc['html']);
        $this->assertStringContainsString('class="collection-method first-collection-method"', $toc['html']);
        // Anchor stays clean and stable.
        $this->assertStringContainsString('id="after"', $toc['html']);
    }

    public function testTocBuilderStripsBadgesFromAnchors(): void
    {
        $html = '<h3>Number::currency <a href="https://example.test/pr" target="_blank" class="since-badge since-pr"><span>v10.38.0</span></a></h3>';
        $toc = \LaravelProDocs\Support\TocBuilder::build($html);

        $this->assertStringContainsString('id="number-currency"', $toc['html']);
        $this->assertStringContainsString('href="#number-currency"', $toc['links']);
        $this->assertStringNotContainsString('v10-38-0', $toc['html']);
    }

    public function testSearchIndexLinksSymbolsToDocsPages(): void
    {
        $symbols = [
            'Number::currency' => ['version' => 'v10.38.0', 'pr' => 49451, 'pr_url' => 'https://example.test/pr/1', 'api_url' => 'https://example.test/api'],
            'Orphan::thing' => ['version' => 'v10.0.0', 'pr' => null, 'pr_url' => null, 'api_url' => null],
        ];

        $dir = sys_get_temp_dir() . '/search_idx_' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/numbers.md', '# Numbers');

        $pages = ['Number::currency' => ['page' => 'numbers', 'anchor' => 'number-currency', 'pages' => ['numbers']]];
        $static = \LaravelProDocs\Support\SearchIndexBuilder::build($dir, $symbols, true, $pages);
        $byTitle = [];
        foreach ($static as $entry) {
            $byTitle[$entry['title']] = $entry;
        }
        $this->assertSame('numbers.html#number-currency', $byTitle['Number::currency']['url']);
        $this->assertNull($byTitle['Orphan::thing']['url']);

        $serve = \LaravelProDocs\Support\SearchIndexBuilder::build($dir, $symbols, false, $pages);
        $byTitle = [];
        foreach ($serve as $entry) {
            $byTitle[$entry['title']] = $entry;
        }
        $this->assertSame('/numbers#number-currency', $byTitle['Number::currency']['url']);

        // Legacy slug-list maps still resolve (without fragment).
        $legacy = \LaravelProDocs\Support\SearchIndexBuilder::build($dir, $symbols, true, ['Number::currency' => ['numbers']]);
        $byTitle = [];
        foreach ($legacy as $entry) {
            $byTitle[$entry['title']] = $entry;
        }
        $this->assertSame('numbers.html', $byTitle['Number::currency']['url']);

        unlink($dir . '/numbers.md');
        rmdir($dir);
    }

    public function testBadgeRendererParity(): void
    {
        $meta = new \LaravelProDocs\Models\SymbolMeta(
            symbol: 'Number::currency',
            version: 'v10.38.0',
            pr: 49451,
            prUrl: 'https://github.com/laravel/framework/pull/49451',
        );

        $tag = \LaravelProDocs\Support\BadgeRenderer::render($meta);

        $this->assertStringContainsString('v="v10.38.0"', (new MarkdownRewriter(new SymbolMatcher($this->makeRegistry())))->formatTag($meta));
        $this->assertStringContainsString('v10.38.0', $tag);
        $this->assertStringContainsString('#49451', $tag);
        $this->assertStringContainsString('since-badge since-pr', $tag);
    }

    private function makeRegistry(): SymbolRegistry
    {
        $registry = new SymbolRegistry();
        $registry->register('Number::currency', 'v10.38.0', 49451, 'https://github.com/laravel/framework/pull/49451');

        return $registry;
    }

    public function testSourceUrlsArePinnedToIntroducingTag(): void
    {
        $extractor = new \LaravelProDocs\Indexer\AstSymbolExtractor();
        $code = <<<'PHP'
<?php

if (! function_exists('rescue')) {
    function rescue(callable $callback)
    {
        return $callback();
    }
}
PHP;

        $pinned = $extractor->extractSymbolsFromCode($code, 'src/Illuminate/Foundation/helpers.php', 'v10.38.0');
        $this->assertArrayHasKey('rescue', $pinned);
        $this->assertStringContainsString('blob/v10.38.0/', (string) $pinned['rescue']);

        // Default behavior (no tag) still points at master for BC.
        $default = $extractor->extractSymbolsFromCode($code, 'src/Illuminate/Foundation/helpers.php');
        $this->assertStringContainsString('blob/master/', (string) $default['rescue']);
    }

    public function testHeaderHeadingExtractionMatchesHeadingsNotProse(): void
    {
        $headings = (function (): array {
            $ref = new \ReflectionMethod(\LaravelProDocs\Indexer\DocsHeaderIndexer::class, 'extractNormalizedHeadings');
            $ref->setAccessible(true);

            return $ref->invoke(null, "## Cache\n\nSome prose about caching things.\n\n### Remember\n");
        })();

        $this->assertArrayHasKey('cache', $headings);
        $this->assertArrayHasKey('remember', $headings);
        // Prose words that are not headings must not be indexed as headings.
        $this->assertArrayNotHasKey('caching things', $headings);
    }
}
