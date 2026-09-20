<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

use LaravelProDocs\Models\SymbolMeta;

/**
 * Single source of truth for <x-since> badge HTML.
 *
 * Used by docs:serve (preview router), docs:build-static, and MarkdownRewriter::formatTag.
 * Keeps version/PR/API badge markup identical everywhere.
 */
final class BadgeRenderer
{
    public const ICON_SVG = '<svg class="external-link-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>';

    public static function render(SymbolMeta $meta): string
    {
        return self::renderParts($meta->version, $meta->pr, $meta->prUrl, $meta->apiUrl);
    }

    public static function renderParts(string $version, ?int $pr, ?string $url, ?string $api): string
    {
        $v = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
        $prEsc = $pr !== null ? htmlspecialchars((string) $pr, ENT_QUOTES, 'UTF-8') : null;
        $urlEsc = $url !== null ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : null;
        $apiEsc = $api !== null ? htmlspecialchars($api, ENT_QUOTES, 'UTF-8') : null;

        $badgeHtml = '';
        if ($prEsc !== null && $urlEsc !== null) {
            $badgeHtml .= sprintf(
                '<a href="%s" target="_blank" rel="noopener" class="since-badge since-pr" title="Introduced in Laravel %s via PR #%s"><span>%s</span><span class="pr-pill">#%s %s</span></a>',
                $urlEsc,
                $v,
                $prEsc,
                $v,
                $prEsc,
                self::ICON_SVG
            );
        } elseif ($urlEsc !== null) {
            $badgeHtml .= sprintf(
                '<a href="%s" target="_blank" rel="noopener" class="since-badge" title="Introduced in Laravel %s"><span>%s %s</span></a>',
                $urlEsc,
                $v,
                $v,
                self::ICON_SVG
            );
        } else {
            $badgeHtml .= sprintf('<span class="since-badge" title="Introduced in Laravel %s">%s</span>', $v, $v);
        }

        if ($apiEsc !== null) {
            $isSource = str_contains($apiEsc, 'github.com');
            $label = $isSource ? 'Source ' . self::ICON_SVG : 'API ' . self::ICON_SVG;
            $title = $isSource ? 'View implementation source code on GitHub' : 'View Laravel API documentation & types';
            $badgeHtml .= sprintf(
                ' <a href="%s" target="_blank" rel="noopener" class="since-badge since-api" title="%s"><span>%s</span></a>',
                $apiEsc,
                $title,
                $label
            );
        }

        return ' ' . $badgeHtml;
    }

    /**
     * Render from a raw <x-since ... /> attribute string (used post-HTML-conversion).
     */
    public static function renderFromAttrs(string $attrs): string
    {
        $v = preg_match('/v="([^"]+)"/', $attrs, $m) ? $m[1] : '';
        $pr = preg_match('/pr="([^"]+)"/', $attrs, $m) ? (int) $m[1] : null;
        $url = preg_match('/url="([^"]+)"/', $attrs, $m) ? $m[1] : null;
        $api = preg_match('/api="([^"]+)"/', $attrs, $m) ? $m[1] : null;

        return self::renderParts($v, $pr, $url, $api);
    }

    /**
     * Replace all <x-since ... /> tags in converted HTML with badge HTML.
     * Returns the original string unchanged if the regex fails.
     */
    public static function replaceInHtml(string $html): string
    {
        $replaced = preg_replace_callback(
            '/<x-since\s+([^>]+)\/>/',
            static fn (array $matches): string => self::renderFromAttrs($matches[1]),
            $html
        );

        return is_string($replaced) ? $replaced : $html;
    }
}
