<?php

declare(strict_types=1);

namespace LaravelProDocs\Rewriter;

use LaravelProDocs\Indexer\SymbolRegistry;
use LaravelProDocs\Models\SymbolMeta;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Parser\MarkdownParser;
use RuntimeException;

class MarkdownRewriter
{
    private MarkdownParser $parser;

    public function __construct(
        private readonly SymbolMatcher $matcher,
    ) {
        $environment = new Environment(['html_input' => 'allow']);
        $environment->addExtension(new CommonMarkCoreExtension());
        $this->parser = new MarkdownParser($environment);
    }

    public static function createFromRegistry(SymbolRegistry $registry): self
    {
        return new self(new SymbolMatcher($registry));
    }

    /**
     * Rewrite a markdown content string, injecting <x-since> tags where applicable.
     *
     * @param string $markdown Markdown text to rewrite
     * @param string|null $docSlug Optional document file slug (e.g. "cache", "eloquent") for contextual symbol resolution
     */
    public function rewrite(string $markdown, ?string $docSlug = null): string
    {
        if (trim($markdown) === '') {
            return $markdown;
        }

        // Validate markdown AST without throwing fatal error
        try {
            $this->parser->parse($markdown);
        } catch (\Throwable) {
            // If AST parser fails, return original content safely
            return $markdown;
        }

        $lines = explode("\n", $markdown);
        $rewrittenLines = [];
        $inFencedCodeBlock = false;
        $fenceDelimiter = null;

        foreach ($lines as $line) {
            // Check for fenced code block toggle (``` or ~~~)
            if (preg_match('/^\s*(```+|~~~+)/', $line, $fenceMatches)) {
                $delimiter = $fenceMatches[1];
                if (!$inFencedCodeBlock) {
                    $inFencedCodeBlock = true;
                    $fenceDelimiter = substr($delimiter, 0, 3);
                } elseif ($fenceDelimiter !== null && str_starts_with(trim($line), $fenceDelimiter)) {
                    $inFencedCodeBlock = false;
                    $fenceDelimiter = null;
                }

                $rewrittenLines[] = $line;
                continue;
            }

            // If inside fenced code block, do not modify
            if ($inFencedCodeBlock) {
                $rewrittenLines[] = $line;
                continue;
            }

            $rewrittenLines[] = $this->rewriteLine($line, $docSlug);
        }

        return implode("\n", $rewrittenLines);
    }

    /**
     * Rewrite a single markdown line.
     */
    private function rewriteLine(string $line, ?string $docSlug = null): string
    {
        // Skip TOC navigation lines (e.g. - [Introduction](#introduction) or - [The `queue:work` Command](#cmd))
        if (preg_match('/^\s*[-*+]\s+\[.*\]\(#[^)]+\)\s*$/', $line)) {
            // Clean up any previously injected tags inside TOC links
            return (string) preg_replace('/\s*<x-since[^>]*\/>/', '', $line);
        }

        // 1. Check if line is a Heading (e.g. # Title, ## Section, ### `Number::currency()`)
        if (preg_match('/^(#{1,6}\s+)(.*)$/', $line, $hMatches)) {
            $prefix = $hMatches[1];
            $headingBody = $hMatches[2];
            $level = strlen(trim($prefix));

            // Extract existing <x-since> tag if present
            $cleanBody = trim(preg_replace('/\s*<x-since[^>]*\/>/', '', $headingBody));
            $meta = $this->matcher->matchHeading($cleanBody, $level, $docSlug);

            if ($meta !== null) {
                $tag = $this->formatTag($meta);
                return rtrim($prefix . $cleanBody) . ' ' . $tag;
            }

            // If heading didn't match as a whole, allow inline code replacement within heading
            $rewrittenBody = $this->rewriteTextExcludingLinks($cleanBody, $docSlug);
            return $prefix . $rewrittenBody;
        }

        // 2. Check if line is an Available Methods / Rules / Assertions index list item:
        // Examples: [after](#method-after), [Ascii](#rule-ascii), [assertConflict](#assert-conflict)
        if (preg_match('/^(\[([^\]]+)\]\(#(?:method-|rule-|assert-)?([a-zA-Z0-9_-]+)\))(?:\s*(<x-since[^>]*\/>))?$/', $line, $listMatch)) {
            $fullLink = $listMatch[1];
            $linkText = trim($listMatch[2]);
            $anchorSlug = $listMatch[3];
            $cleanLink = (string) preg_replace('/\s*<x-since[^>]*\/>/', '', $fullLink);

            $ruleName = str_replace('-', '_', strtolower($anchorSlug));

            if ($docSlug === 'validation') {
                $meta = $this->matcher->match("rule:{$ruleName}", $docSlug)
                    ?? $this->matcher->match("validation:{$ruleName}", $docSlug);
                if ($meta !== null && ($meta->pr !== null || $meta->version !== 'v9.0.0')) {
                    $tag = $this->formatTag($meta);
                    return "{$cleanLink} {$tag}";
                }
            } elseif ($docSlug === 'http-tests') {
                $meta = $this->matcher->match("TestResponse::{$linkText}", $docSlug);
                if ($meta !== null && ($meta->pr !== null || $meta->version !== 'v9.0.0')) {
                    $tag = $this->formatTag($meta);
                    return "{$cleanLink} {$tag}";
                }
            } elseif ($docSlug === 'collections' || $docSlug === 'eloquent-collections') {
                $candidate = ($docSlug === 'collections') ? "Collection::{$linkText}" : "EloquentCollection::{$linkText}";
                $meta = $this->matcher->match($candidate, $docSlug) ?? $this->matcher->match("Collection::{$linkText}", $docSlug);
                if ($meta !== null && ($meta->pr !== null || $meta->version !== 'v9.0.0')) {
                    $tag = $this->formatTag($meta);
                    return "{$cleanLink} {$tag}";
                }
            } elseif ($docSlug === 'strings') {
                $meta = $this->matcher->match("Str::{$linkText}", $docSlug);
                if ($meta !== null && ($meta->pr !== null || $meta->version !== 'v9.0.0')) {
                    $tag = $this->formatTag($meta);
                    return "{$cleanLink} {$tag}";
                }
            }

            return $cleanLink;
        }

        // 3. Rewrite inline code tokens in standard lines, excluding links
        return $this->rewriteTextExcludingLinks($line, $docSlug);
    }

    /**
     * Rewrite inline code tokens within text while protecting markdown links and HTML <a> tags.
     */
    private function rewriteTextExcludingLinks(string $content, ?string $docSlug = null): string
    {
        // Protect Markdown links [text](url) and [text][ref]
        $links = [];
        $protected = (string) preg_replace_callback(
            '/\[([^\]]*)\]\(([^)]*)\)|\[([^\]]*)\]\[([^\]]*)\]/',
            function (array $match) use (&$links): string {
                $placeholder = "\x00LINK_" . count($links) . "\x00";
                // Strip any previously injected <x-since> from inside the link text
                $cleanFullMatch = preg_replace('/\s*<x-since[^>]*\/>/', '', $match[0]);
                $links[$placeholder] = $cleanFullMatch;
                return $placeholder;
            },
            $content
        );

        // Protect HTML <a> tags
        $htmlLinks = [];
        $protected = (string) preg_replace_callback(
            '/<a\s+[^>]*>.*?<\/a>/is',
            function (array $match) use (&$htmlLinks): string {
                $placeholder = "\x00HTMLLINK_" . count($htmlLinks) . "\x00";
                $htmlLinks[$placeholder] = $match[0];
                return $placeholder;
            },
            $protected
        );

        $rewritten = $this->rewriteInlineCodeTokens($protected, $docSlug);

        // Restore HTML links
        if (!empty($htmlLinks)) {
            $rewritten = strtr($rewritten, $htmlLinks);
        }

        // Restore Markdown links
        if (!empty($links)) {
            $rewritten = strtr($rewritten, $links);
        }

        return $rewritten;
    }

    /**
     * Rewrite inline code tokens within a line while preserving existing tags idempotently.
     */
    private function rewriteInlineCodeTokens(string $content, ?string $docSlug = null): string
    {
        return (string) preg_replace_callback(
            '/(?<!`)(`([^`\n]+)`)(?:\s*(<x-since[^>]*\/>))?(?!`)/',
            function (array $matches) use ($docSlug): string {
                $fullBackticks = $matches[1];
                $codeContent = $matches[2];
                $existingTag = $matches[3] ?? null;

                $meta = $this->matcher->match($codeContent, $docSlug);
                if ($meta !== null) {
                    $tag = $this->formatTag($meta);
                    return "{$fullBackticks} {$tag}";
                }

                // If no match found but tag already existed, preserve it
                if ($existingTag !== null) {
                    return "{$fullBackticks} {$existingTag}";
                }

                return $fullBackticks;
            },
            $content
        );
    }

    /**
     * Format the <x-since> tag string.
     */
    public function formatTag(SymbolMeta $meta): string
    {
        $attrs = [sprintf('v="%s"', $meta->version)];

        if ($meta->pr !== null && $meta->prUrl !== null) {
            $attrs[] = sprintf('pr="%d"', $meta->pr);
            $attrs[] = sprintf('url="%s"', $meta->prUrl);
        } elseif ($meta->prUrl !== null && !str_starts_with($meta->symbol, 'header:') && !str_starts_with($meta->symbol, 'page:')) {
            $attrs[] = sprintf('url="%s"', $meta->prUrl);
        }

        if ($meta->apiUrl !== null) {
            $attrs[] = sprintf('api="%s"', $meta->apiUrl);
        }

        return '<x-since ' . implode(' ', $attrs) . ' />';
    }

    /**
     * Rewrite a single file from source to target.
     *
     * @return array{modified: bool, tagsCount: int}
     */
    public function rewriteFile(string $sourcePath, string $targetPath): array
    {
        if (!file_exists($sourcePath)) {
            throw new RuntimeException("Source file does not exist: {$sourcePath}");
        }

        $content = file_get_contents($sourcePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read file: {$sourcePath}");
        }

        $docSlug = basename($sourcePath, '.md');
        $rewritten = $this->rewrite($content, $docSlug);
        $isModified = ($content !== $rewritten);

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Unable to create target directory: {$targetDir}");
        }

        if (file_put_contents($targetPath, $rewritten) === false) {
            throw new RuntimeException("Unable to write file: {$targetPath}");
        }

        $tagsCount = substr_count($rewritten, '<x-since');

        return [
            'modified' => $isModified,
            'tagsCount' => $tagsCount,
        ];
    }

    /**
     * Rewrite all markdown files in a directory recursively.
     *
     * @param callable(string $relativeFile, bool $modified, int $tagsCount): void|null $progressCallback
     * @return array{filesScanned: int, filesModified: int, totalTags: int}
     */
    public function rewriteDirectory(string $sourceDir, string $outputDir, ?callable $progressCallback = null): array
    {
        if (!is_dir($sourceDir)) {
            throw new RuntimeException("Source docs directory not found: {$sourceDir}");
        }

        $filesScanned = 0;
        $filesModified = 0;
        $totalTags = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'md') {
                continue;
            }

            $sourcePath = $item->getPathname();
            $relativePath = ltrim(substr($sourcePath, strlen($sourceDir)), '/\\');
            $targetPath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . $relativePath;

            $result = $this->rewriteFile($sourcePath, $targetPath);

            $filesScanned++;
            if ($result['modified']) {
                $filesModified++;
            }
            $totalTags += $result['tagsCount'];

            if ($progressCallback !== null) {
                $progressCallback($relativePath, $result['modified'], $result['tagsCount']);
            }
        }

        return [
            'filesScanned' => $filesScanned,
            'filesModified' => $filesModified,
            'totalTags' => $totalTags,
        ];
    }
}
