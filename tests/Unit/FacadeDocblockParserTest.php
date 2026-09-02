<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Unit;

use LaravelProDocs\Indexer\FacadeDocblockParser;
use PHPUnit\Framework\TestCase;

class FacadeDocblockParserTest extends TestCase
{
    private FacadeDocblockParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FacadeDocblockParser();
    }

    public function testParsesStaticMethodsFromDocblock(): void
    {
        $doc = <<<'DOC'
/**
 * @method static string currency(float|int $number, string $in = 'USD', string|null $locale = null)
 * @method static string spell(float|int $number, string|null $locale = null)
 * @method static string ordinal(int $number, string|null $locale = null)
 * @method static \Illuminate\Support\Stringable of(string $string)
 * @see \Illuminate\Support\Number
 */
DOC;

        $methods = $this->parser->parseMethods($doc);

        $this->assertContains('currency', $methods);
        $this->assertContains('spell', $methods);
        $this->assertContains('ordinal', $methods);
        $this->assertContains('of', $methods);
        $this->assertCount(4, $methods);
    }

    public function testParsesNonStaticMethodsAndComplexReturnTypes(): void
    {
        $doc = <<<'DOC'
/**
 * @method mixed get(string $key, mixed $default = null)
 * @method array<string, mixed> all()
 * @method void forget(string|array $keys)
 * @method static bool|null when(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 */
DOC;

        $methods = $this->parser->parseMethods($doc);

        $this->assertContains('get', $methods);
        $this->assertContains('all', $methods);
        $this->assertContains('forget', $methods);
        $this->assertContains('when', $methods);
    }

    public function testHandlesEmptyDocblock(): void
    {
        $this->assertSame([], $this->parser->parseMethods(''));
        $this->assertSame([], $this->parser->parseMethods('/** No method tags here */'));
    }
}
