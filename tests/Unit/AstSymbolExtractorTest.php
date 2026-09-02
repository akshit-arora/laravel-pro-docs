<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Unit;

use LaravelProDocs\Indexer\AstSymbolExtractor;
use PHPUnit\Framework\TestCase;

class AstSymbolExtractorTest extends TestCase
{
    private AstSymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new AstSymbolExtractor();
    }

    public function testExtractsClassPublicMethodsAndFQCN(): void
    {
        $code = <<<'PHP'
<?php

namespace Illuminate\Support;

class Number
{
    public function currency(float|int $number, string $in = 'USD'): string
    {
        return '$100';
    }

    public static function spell(float|int $number): string
    {
        return 'ten';
    }

    protected function internalHelper(): void
    {
    }

    private function secret(): void
    {
    }

    public function __construct()
    {
    }
}
PHP;

        $symbols = $this->extractor->extractSymbolsFromCode($code);

        $this->assertArrayHasKey('Number::currency', $symbols);
        $this->assertSame('https://api.laravel.com/docs/master/Illuminate/Support/Number.html#method_currency', $symbols['Number::currency']);
        $this->assertArrayHasKey('Illuminate\Support\Number::currency', $symbols);
        $this->assertArrayHasKey('Number::spell', $symbols);
        $this->assertArrayHasKey('Illuminate\Support\Number::spell', $symbols);

        // Protected, private, and magic methods must NOT be indexed
        $this->assertArrayNotHasKey('Number::internalHelper', $symbols);
        $this->assertArrayNotHasKey('Number::secret', $symbols);
        $this->assertArrayNotHasKey('Number::__construct', $symbols);
    }

    public function testExtractsGlobalHelperFunctions(): void
    {
        $code = <<<'PHP'
<?php

if (! function_exists('rescue')) {
    function rescue(callable $callback, mixed $rescue = null, bool $report = true)
    {
        return $callback();
    }
}

if (! function_exists('str')) {
    function str(?string $string = null)
    {
        return $string;
    }
}
PHP;

        $symbolsWithFilePath = $this->extractor->extractSymbolsFromCode($code, 'src/Illuminate/Foundation/helpers.php');
        $this->assertArrayHasKey('rescue', $symbolsWithFilePath);
        $this->assertSame('https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/helpers.php#L4-L7', $symbolsWithFilePath['rescue']);

        $symbols = $this->extractor->extractSymbolsFromCode($code);
        $this->assertArrayHasKey('rescue', $symbols);
        $this->assertSame('https://laravel.com/docs/master/helpers#method-rescue', $symbols['rescue']);
        $this->assertArrayHasKey('str', $symbols);
    }

    public function testExtractsFacadeDocblockMethods(): void
    {
        $code = <<<'PHP'
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static string currency(float|int $number, string $in = 'USD')
 * @method static string spell(float|int $number)
 * @see \Illuminate\Support\Number
 */
class Number extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'number';
    }
}
PHP;

        $symbols = $this->extractor->extractSymbolsFromCode($code);

        $this->assertArrayHasKey('Number::currency', $symbols);
        $this->assertArrayHasKey('Illuminate\Support\Facades\Number::currency', $symbols);
        $this->assertArrayHasKey('Number::spell', $symbols);
        $this->assertArrayHasKey('Illuminate\Support\Facades\Number::spell', $symbols);
    }

    public function testHandlesSyntaxErrorsGracefully(): void
    {
        $invalidCode = '<?php class Broken { public function ( {';
        $symbols = $this->extractor->extractSymbolsFromCode($invalidCode);

        $this->assertSame([], $symbols);
    }
}
