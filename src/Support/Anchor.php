<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

/**
 * Single source of truth for heading anchor slugs.
 *
 * The same slug must come out whether it is computed at rewrite time (from
 * markdown heading text, to record where a symbol was tagged) or at render
 * time (from converted HTML headings, to inject ids + TOC links). Any drift
 * between the two breaks search deep-links, so both paths funnel through here.
 */
final class Anchor
{
    public static function slugify(string $text): string
    {
        $slugged = preg_replace('/[^a-zA-Z0-9]+/', '-', $text);

        return strtolower(trim(is_string($slugged) ? $slugged : $text, '-'));
    }

    /**
     * Anchor for a markdown heading body (without the leading #'s).
     * Mirrors what the rendered HTML heading will slugify to.
     */
    public static function fromMarkdownHeading(string $headingBody): string
    {
        $text = preg_replace('/\s*<x-since[^>]*\/>/', '', $headingBody);
        $text = is_string($text) ? $text : $headingBody;

        // Markdown links/images render as their text content.
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/', '$1', $text);
        $text = is_string($text) ? $text : '';

        // `{.collection-method}` style markers are literal text in the HTML,
        // but excluding them yields cleaner, stable anchors.
        $text = preg_replace('/\{\s*\.[^}]+\}/', '', $text);
        $text = is_string($text) ? $text : '';

        return self::slugify($text);
    }

    /**
     * Anchor for a rendered HTML heading inner (badges excluded so ids stay
     * clean and stable when version/PR metadata changes).
     */
    public static function fromHtmlHeading(string $innerHtml): string
    {
        return self::slugify(self::cleanHtmlHeading($innerHtml));
    }

    /**
     * Plain-text title of a rendered HTML heading, badges/markers removed.
     * Used for TOC link labels.
     */
    public static function cleanHtmlHeading(string $innerHtml): string
    {
        $text = preg_replace('/<(a|span)\b[^>]*\bsince-badge\b[^>]*>.*?<\/\1>/is', '', $innerHtml);
        $text = is_string($text) ? $text : $innerHtml;
        $text = strip_tags($text);
        $text = preg_replace('/\{\s*\.[^}]+\}/', '', $text);

        return trim(is_string($text) ? $text : '');
    }
}
