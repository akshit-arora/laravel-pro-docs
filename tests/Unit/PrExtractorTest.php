<?php

declare(strict_types=1);

namespace LaravelProDocs\Tests\Unit;

use LaravelProDocs\Git\PrExtractor;
use PHPUnit\Framework\TestCase;

class PrExtractorTest extends TestCase
{
    private PrExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PrExtractor();
    }

    public function testExtractsMergePullRequestFormat(): void
    {
        $message = "Merge pull request #49451 from laravel/feature-number-currency\n\nAdd Number::currency helper";
        $pr = $this->extractor->extractPrNumber($message);

        $this->assertSame(49451, $pr);
    }

    public function testExtractsBracketPrFormat(): void
    {
        $message = "[10.x] Add Number::currency helper (#49451)\n\nThis introduces the currency helper method.";
        $pr = $this->extractor->extractPrNumber($message);

        $this->assertSame(49451, $pr);
    }

    public function testExtractsFixesAndClosesKeywords(): void
    {
        $this->assertSame(50123, $this->extractor->extractPrNumber('Fixes #50123 by adding Context::add'));
        $this->assertSame(50124, $this->extractor->extractPrNumber('closes #50124'));
        $this->assertSame(50125, $this->extractor->extractPrNumber('Resolved #50125'));
    }

    public function testExtractsGitHubPullUrl(): void
    {
        $message = 'Refer to https://github.com/laravel/framework/pull/49451 for discussion';
        $pr = $this->extractor->extractPrNumber($message);

        $this->assertSame(49451, $pr);
    }

    public function testReturnsNullWhenNoPrFound(): void
    {
        $message = 'Update composer dependencies and fix typo';
        $pr = $this->extractor->extractPrNumber($message);

        $this->assertNull($pr);
    }

    public function testExtractsAllUniquePrNumbers(): void
    {
        $message = "[10.x] Feature (#49451)\nMerge pull request #49451 from branch\nAlso related to (#49452)";
        $prs = $this->extractor->extractAllPrNumbers($message);

        $this->assertEquals([49451, 49452], $prs);
    }

    public function testFindPrForSymbolPrioritizesMentionedSymbol(): void
    {
        $commits = [
            'General refactoring (#49100)',
            '[10.x] Add Number::currency method (#49451)',
            'Add Number::spell method (#49452)',
        ];

        $prCurrency = $this->extractor->findPrForSymbol($commits, 'Number::currency');
        $this->assertSame(49451, $prCurrency);

        $prSpell = $this->extractor->findPrForSymbol($commits, 'spell');
        $this->assertSame(49452, $prSpell);

        $strictFallback = $this->extractor->findPrForSymbol($commits, 'unknownSymbol', true);
        $this->assertNull($strictFallback);
    }

    public function testGeneratePrUrl(): void
    {
        $url = $this->extractor->generatePrUrl(49451);
        $this->assertSame('https://github.com/laravel/framework/pull/49451', $url);

        $this->assertNull($this->extractor->generatePrUrl(null));
    }

    public function testGenerateUrlWithReleaseFallback(): void
    {
        $this->assertSame('https://github.com/laravel/framework/pull/49451', $this->extractor->generateUrl(49451, 'v10.38.0'));
        $this->assertSame('https://github.com/laravel/framework/releases/tag/v9.32.0', $this->extractor->generateUrl(null, 'v9.32.0'));
        $this->assertNull($this->extractor->generateUrl(null, null));
    }
}
