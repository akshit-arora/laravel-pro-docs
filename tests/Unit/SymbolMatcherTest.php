<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Unit;

use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Rewriter\SymbolMatcher;
use PHPUnit\Framework\TestCase;

class SymbolMatcherTest extends TestCase
{
    private SymbolMatcher $matcher;

    protected function setUp(): void
    {
        $registry = new SymbolRegistry();
        $registry->register('Number::currency', 'v10.38.0', 49451, 'https://github.com/laravel/framework/pull/49451');
        $registry->register('Illuminate\Support\Number::spell', 'v10.38.0', 49451, 'https://github.com/laravel/framework/pull/49451');
        $registry->register('Context::add', 'v11.0.0', 50123, 'https://github.com/laravel/framework/pull/50123');
        $registry->register('rescue', 'v9.0.0', null, null);
        $registry->register('str', 'v9.0.0', null, null);

        $this->matcher = new SymbolMatcher($registry);
    }

    public function testMatchesDirectSymbols(): void
    {
        $meta = $this->matcher->match('Number::currency');
        $this->assertNotNull($meta);
        $this->assertSame('v10.38.0', $meta->version);
        $this->assertSame(49451, $meta->pr);

        $contextMeta = $this->matcher->match('Context::add');
        $this->assertNotNull($contextMeta);
        $this->assertSame('v11.0.0', $contextMeta->version);
    }

    public function testMatchesMethodCallsWithArguments(): void
    {
        $meta = $this->matcher->match('Number::currency($amount, "USD")');
        $this->assertNotNull($meta);
        $this->assertSame('v10.38.0', $meta->version);

        $helperMeta = $this->matcher->match('rescue(fn () => true)');
        $this->assertNotNull($helperMeta);
        $this->assertSame('v9.0.0', $helperMeta->version);
    }

    public function testResolvesFqcnToShortClass(): void
    {
        $meta = $this->matcher->match('Illuminate\Support\Number::spell(10)');
        $this->assertNotNull($meta);
        $this->assertSame('v10.38.0', $meta->version);
    }

    public function testGuardrailsRejectGenericInstanceMethods(): void
    {
        $this->assertNull($this->matcher->match('->get()'));
        $this->assertNull($this->matcher->match('->find(1)'));
        $this->assertNull($this->matcher->match('->save()'));
        $this->assertNull($this->matcher->match('->all()'));
        $this->assertNull($this->matcher->match('->where("id", 1)'));
        $this->assertNull($this->matcher->match('$this->get()'));
    }

    public function testGuardrailsRejectGenericBareKeywords(): void
    {
        $this->assertNull($this->matcher->match('get'));
        $this->assertNull($this->matcher->match('find'));
        $this->assertNull($this->matcher->match('save'));
        $this->assertNull($this->matcher->match('update'));
        $this->assertNull($this->matcher->match(''));
    }

    public function testRejectsBareWordsWithoutParentheses(): void
    {
        // Unadorned words like port, node, sync must not resolve to methods unless called with ()
        $this->assertNull($this->matcher->match('port', 'cache'));
        $this->assertNull($this->matcher->match('node', 'installation'));
        $this->assertNull($this->matcher->match('sync', 'concurrency'));
        $this->assertNull($this->matcher->match('currency', 'installation'));
    }

    public function testMatchesContextMethodsWhenCalledAsFunction(): void
    {
        // When written as a call with (), it should resolve via context map
        $meta = $this->matcher->match('add("key", "val")', 'context');
        $this->assertNotNull($meta);
        $this->assertSame('v11.0.0', $meta->version);
    }

    public function testHeadingPrioritizesExactAstSymbol(): void
    {
        // AST symbol match takes precedence over coarse header branch metadata
        $meta = $this->matcher->matchHeading('#### `Number::currency()` {.collection-method}', 4, 'strings');
        $this->assertNotNull($meta);
        $this->assertSame('v10.38.0', $meta->version);
        $this->assertSame(49451, $meta->pr);
    }
}
