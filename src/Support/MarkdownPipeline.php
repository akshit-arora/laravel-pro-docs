<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Shared markdown -> HTML pipeline for docs:serve and docs:build-static.
 *
 * Order matters: normalize markdown, convert to HTML, THEN replace
 * <x-since> tags with badges (avoids CommonMark escaping badge HTML).
 */
final class MarkdownPipeline
{
    public static function createConverter(): MarkdownConverter
    {
        // The GFM extension bundles DisallowedRawHtml, which escapes <style>
        // blocks by default. Laravel's own docs ship <style> blocks (e.g. the
        // 3-column .collection-method-list layout), so allowlist `style` while
        // keeping genuinely dangerous tags (script, iframe, ...) escaped.
        $environment = new Environment([
            'html_input' => 'allow',
            'disallowed_raw_html' => [
                'disallowed_tags' => ['title', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'script', 'plaintext'],
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return new MarkdownConverter($environment);
    }

    public static function normalizeMarkdown(string $markdown, string $versionLinkReplacement): string
    {
        $replaced = preg_replace('/\/docs\/\{\{version\}\}\/([a-zA-Z0-9_-]+)/', $versionLinkReplacement, $markdown);
        $markdown = is_string($replaced) ? $replaced : $markdown;

        $replaced = preg_replace('/^>\s*\[!NOTE\]\s*/m', '> **Note:** ', $markdown);
        $markdown = is_string($replaced) ? $replaced : $markdown;

        $replaced = preg_replace('/^>\s*\[!WARNING\]\s*/m', '> **Warning:** ', $markdown);

        return is_string($replaced) ? $replaced : $markdown;
    }

    public static function toHtml(string $markdown, MarkdownConverter $converter, string $versionLinkReplacement): string
    {
        $normalized = self::normalizeMarkdown($markdown, $versionLinkReplacement);
        $html = (string) $converter->convert($normalized);

        return BadgeRenderer::replaceInHtml($html);
    }
}
