<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Integration;

use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Rewriter\MarkdownRewriter;
use LaravelProDocs\Rewriter\SymbolMatcher;
use PHPUnit\Framework\TestCase;

class MarkdownRewriterTest extends TestCase
{
    private MarkdownRewriter $rewriter;
    private SymbolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new SymbolRegistry();
        $this->registry->register('Number::currency', 'v10.38.0', 49451, 'https://github.com/laravel/framework/pull/49451');
        $this->registry->register('Context::add', 'v11.0.0', 50123, 'https://github.com/laravel/framework/pull/50123');
        $this->registry->register('str', 'v9.0.0', null, null);
        $this->registry->register('rescue', 'v9.0.0', null, null);

        $matcher = new SymbolMatcher($this->registry);
        $this->rewriter = new MarkdownRewriter($matcher);
    }

    public function testRewritesInlineCodeNodes(): void
    {
        $input = 'You can format numbers using `Number::currency($amount)` easily.';
        $expected = 'You can format numbers using `Number::currency($amount)` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" /> easily.';

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testRewritesHeadings(): void
    {
        $input = "### Number::currency\n\nMethod details here.";
        $expected = "### Number::currency <x-since v=\"v10.38.0\" pr=\"49451\" url=\"https://github.com/laravel/framework/pull/49451\" />\n\nMethod details here.";

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testRewritesHeadingsWithBackticks(): void
    {
        $input = "#### `Context::add`\n\nContext documentation.";
        $expected = "#### `Context::add` <x-since v=\"v11.0.0\" pr=\"50123\" url=\"https://github.com/laravel/framework/pull/50123\" />\n\nContext documentation.";

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testIdempotencyDoesNotDuplicateTags(): void
    {
        $input = 'Use `Number::currency()` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" /> for currency.';
        $firstPass = $this->rewriter->rewrite($input);
        $secondPass = $this->rewriter->rewrite($firstPass);

        $this->assertSame($input, $firstPass);
        $this->assertSame($input, $secondPass);
    }

    public function testUpdatesOutdatedTagAttributes(): void
    {
        $input = 'Use `Number::currency()` <x-since v="v10.0.0" /> for currency.';
        $expected = 'Use `Number::currency()` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" /> for currency.';

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testDoesNotModifyGenericInstanceMethods(): void
    {
        $input = 'Call `->get()` or `->find($id)` or `->save()` to interact with the database.';
        $this->assertSame($input, $this->rewriter->rewrite($input));
    }

    public function testPreservesFencedCodeBlocksIntact(): void
    {
        $input = <<<'MD'
# Number Formatting

You can format numbers using `Number::currency(100)`:

```php
use Illuminate\Support\Number;

$price = Number::currency(1000);
$user->get();
```

Use `rescue()` to safely execute code.
MD;

        $expected = <<<'MD'
# Number Formatting

You can format numbers using `Number::currency(100)` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" />:

```php
use Illuminate\Support\Number;

$price = Number::currency(1000);
$user->get();
```

Use `rescue()` <x-since v="v9.0.0" /> to safely execute code.
MD;

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testRewriteDirectoryRecursively(): void
    {
        $tempSource = sys_get_temp_dir() . '/docs_src_' . uniqid();
        $tempOutput = sys_get_temp_dir() . '/docs_out_' . uniqid();

        mkdir($tempSource . '/nested', 0755, true);
        file_put_contents($tempSource . '/helpers.md', 'Use `str("text")` or `rescue(fn () => 1)` here.');
        file_put_contents($tempSource . '/nested/numbers.md', '### Number::currency' . "\n\n" . 'Format `Number::currency()` here.');

        $result = $this->rewriter->rewriteDirectory($tempSource, $tempOutput);

        $this->assertSame(2, $result['filesScanned']);
        $this->assertSame(2, $result['filesModified']);
        $this->assertGreaterThanOrEqual(4, $result['totalTags']);

        $outputHelper = file_get_contents($tempOutput . '/helpers.md');
        $this->assertStringContainsString('<x-since v="v9.0.0" />', $outputHelper);

        $outputNumber = file_get_contents($tempOutput . '/nested/numbers.md');
        $this->assertStringContainsString('<x-since v="v10.38.0" pr="49451"', $outputNumber);

        // Cleanup
        unlink($tempSource . '/nested/numbers.md');
        unlink($tempSource . '/helpers.md');
        rmdir($tempSource . '/nested');
        rmdir($tempSource);

        unlink($tempOutput . '/nested/numbers.md');
        unlink($tempOutput . '/helpers.md');
        rmdir($tempOutput . '/nested');
        rmdir($tempOutput);
    }

    public function testRewritesInTableCellsAndListItems(): void
    {
        $input = <<<'MD'
| Method | Description |
| --- | --- |
| `Number::currency()` | Currency formatter |
| `rescue()` | Safely execute |

- Option 1: Use `Number::currency()`
- Option 2: Use `rescue()`
MD;

        $rewritten = $this->rewriter->rewrite($input);

        $this->assertStringContainsString('| `Number::currency()` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" /> |', $rewritten);
        $this->assertStringContainsString('| `rescue()` <x-since v="v9.0.0" /> |', $rewritten);
        $this->assertStringContainsString('- Option 1: Use `Number::currency()` <x-since v="v10.38.0" pr="49451" url="https://github.com/laravel/framework/pull/49451" />', $rewritten);
        $this->assertStringContainsString('- Option 2: Use `rescue()` <x-since v="v9.0.0" />', $rewritten);
    }

    public function testProtectsMarkdownLinksFromNestedBadgeInjection(): void
    {
        $input = 'Check [the `Number::currency()` helper](/docs/numbers) or [`rescue()`](#safe-exec) for details.';
        $rewritten = $this->rewriter->rewrite($input);

        // Link text should NOT have <x-since> injected inside [ ... ]
        $this->assertStringContainsString('[the `Number::currency()` helper](/docs/numbers)', $rewritten);
        $this->assertStringContainsString('[`rescue()`](#safe-exec)', $rewritten);
        $this->assertStringNotContainsString('[`Number::currency()` <x-since', $rewritten);
    }

    public function testProtectsTableOfContentsNavigation(): void
    {
        $input = <<<'MD'
- [Introduction](#introduction)
- [Formatting Numbers](#formatting)
    - [The `Number::currency()` Method](#number-currency)
    - [Using `rescue()` Safely](#rescue-safe)
MD;

        $rewritten = $this->rewriter->rewrite($input);

        $this->assertSame($input, $rewritten);
    }

    public function testCleansUpPreviouslyNestedTagsInsideLinks(): void
    {
        $input = 'Visit [`Number::currency()` <x-since v="v10.38.0" />](/docs/numbers) for details.';
        $expected = 'Visit [`Number::currency()`](/docs/numbers) for details.';

        $this->assertSame($expected, $this->rewriter->rewrite($input));
    }

    public function testEnrichesAvailableMethodsListItems(): void
    {
        $this->registry->register('Collection::after', 'v10.15.0', 47585, 'https://github.com/laravel/framework/pull/47585', 'https://api.laravel.com/docs/master/Illuminate/Support/Collection.html#method_after');

        $input = <<<'MD'
<div class="collection-method-list" markdown="1">

[after](#method-after)
[all](#method-all)

</div>
MD;

        $rewritten = $this->rewriter->rewrite($input, 'collections');

        $this->assertStringContainsString('[after](#method-after) <x-since v="v10.15.0" pr="47585" url="https://github.com/laravel/framework/pull/47585" api="https://api.laravel.com/docs/master/Illuminate/Support/Collection.html#method_after" />', $rewritten);
        $this->assertStringContainsString('[all](#method-all)', $rewritten);
    }

    public function testEnrichesCollectionMethodHeadings(): void
    {
        $this->registry->register('Collection::after', 'v10.15.0', 47585, 'https://github.com/laravel/framework/pull/47585', 'https://api.laravel.com/docs/master/Illuminate/Support/Collection.html#method_after');

        $input = "#### `after()` {.collection-method .first-collection-method}\n\nThe after method returns the item...";
        $rewritten = $this->rewriter->rewrite($input, 'collections');

        $this->assertStringContainsString('#### `after()` {.collection-method .first-collection-method} <x-since v="v10.15.0" pr="47585" url="https://github.com/laravel/framework/pull/47585" api="https://api.laravel.com/docs/master/Illuminate/Support/Collection.html#method_after" />', $rewritten);
    }

    public function testEnrichesValidationRulesAndListItems(): void
    {
        $this->registry->register('rule:ascii', 'v9.33.0', 44376, 'https://github.com/laravel/framework/pull/44376', 'https://github.com/laravel/framework/blob/master/src/Illuminate/Validation/Concerns/ValidatesAttributes.php#L100-L110');
        $this->registry->register('rule:decimal', 'v9.42.0', 45037, 'https://github.com/laravel/framework/pull/45037', 'https://github.com/laravel/framework/blob/master/src/Illuminate/Validation/Concerns/ValidatesAttributes.php#L200-L210');

        $input = <<<'MD'
<div class="collection-method-list" markdown="1">

[Ascii](#rule-ascii)

</div>

<a name="rule-ascii"></a>
#### ascii

The field under validation must be ASCII...

<a name="rule-decimal"></a>
#### decimal:min,max

The field under validation must be decimal...
MD;

        $rewritten = $this->rewriter->rewrite($input, 'validation');

        $this->assertStringContainsString('[Ascii](#rule-ascii) <x-since v="v9.33.0" pr="44376" url="https://github.com/laravel/framework/pull/44376" api="https://github.com/laravel/framework/blob/master/src/Illuminate/Validation/Concerns/ValidatesAttributes.php#L100-L110" />', $rewritten);
        $this->assertStringContainsString('#### ascii <x-since v="v9.33.0" pr="44376" url="https://github.com/laravel/framework/pull/44376" api="https://github.com/laravel/framework/blob/master/src/Illuminate/Validation/Concerns/ValidatesAttributes.php#L100-L110" />', $rewritten);
        $this->assertStringContainsString('#### decimal:min,max <x-since v="v9.42.0" pr="45037" url="https://github.com/laravel/framework/pull/45037" api="https://github.com/laravel/framework/blob/master/src/Illuminate/Validation/Concerns/ValidatesAttributes.php#L200-L210" />', $rewritten);
    }

    public function testEnrichesTestingAssertionsAndListItems(): void
    {
        $this->registry->register('TestResponse::assertJsonIsArray', 'v9.38.0', 44778, 'https://github.com/laravel/framework/pull/44778', 'https://api.laravel.com/docs/master/Illuminate/Testing/TestResponse.html#method_assertJsonIsArray');

        $input = <<<'MD'
<div class="collection-method-list" markdown="1">

[assertJsonIsArray](#assert-json-is-array)

</div>

<a name="assert-json-is-array"></a>
#### assertJsonIsArray

Assert that the response JSON is an array...
MD;

        $rewritten = $this->rewriter->rewrite($input, 'http-tests');

        $this->assertStringContainsString('[assertJsonIsArray](#assert-json-is-array) <x-since v="v9.38.0" pr="44778" url="https://github.com/laravel/framework/pull/44778" api="https://api.laravel.com/docs/master/Illuminate/Testing/TestResponse.html#method_assertJsonIsArray" />', $rewritten);
        $this->assertStringContainsString('#### assertJsonIsArray <x-since v="v9.38.0" pr="44778" url="https://github.com/laravel/framework/pull/44778" api="https://api.laravel.com/docs/master/Illuminate/Testing/TestResponse.html#method_assertJsonIsArray" />', $rewritten);
    }
}
