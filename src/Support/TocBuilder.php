<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

/**
 * Extracts the on-this-page TOC (H2/H3) and injects heading ids + anchors.
 */
final class TocBuilder
{
    /**
     * @return array{links: string, html: string}
     */
    public static function build(string $htmlContent): array
    {
        $tocLinks = '';
        if (preg_match_all('/<h([23])[^>]*>(.*?)<\/h\1>/i', $htmlContent, $tocMatches, PREG_SET_ORDER) === false) {
            $tocMatches = [];
        }

        foreach ($tocMatches as $match) {
            $level = $match[1];
            $rawHeading = Anchor::cleanHtmlHeading($match[2]);
            $anchor = Anchor::slugify($rawHeading);
            $indentClass = $level === '3' ? 'toc-h3' : 'toc-h2';
            $tocLinks .= sprintf(
                '<li class="%s"><a href="#%s">%s</a></li>',
                $indentClass,
                htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($rawHeading, ENT_QUOTES, 'UTF-8')
            );
        }

        $replaced = preg_replace_callback(
            '/<h([123456])([^>]*)>(.*?)<\/h\1>/i',
            static function (array $m): string {
                $level = $m[1];
                $attrs = $m[2];
                $inner = $m[3];
                $anchor = Anchor::fromHtmlHeading($inner);
                if (!str_contains($attrs, 'id=')) {
                    $attrs .= ' id="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '"';
                }

                // Laravel's docs use `{.class}` heading markers (e.g.
                // `#### after() {.collection-method}`). Their site turns these
                // into heading classes; without that they render as literal
                // text, so promote them to a class attribute here.
                if (preg_match_all('/\{\s*(\.[^}]+)\}/', $inner, $markerMatches)) {
                    $classes = [];
                    foreach ($markerMatches[1] as $markerClasses) {
                        foreach (preg_split('/\s+/', trim($markerClasses)) ?: [] as $class) {
                            $class = ltrim($class, '.');
                            if ($class !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $class)) {
                                $classes[] = $class;
                            }
                        }
                    }
                    if ($classes !== []) {
                        $innerResult = preg_replace('/\{\s*\.[^}]+\}/', '', $inner);
                        $inner = is_string($innerResult) ? $innerResult : $inner;
                        $classAttr = implode(' ', array_values(array_unique($classes)));
                        if (preg_match('/\bclass="([^"]*)"/i', $attrs, $classMatch)) {
                            $attrs = (string) preg_replace(
                                '/\bclass="([^"]*)"/i',
                                'class="$1 ' . $classAttr . '"',
                                $attrs,
                                1
                            );
                        } else {
                            $attrs .= ' class="' . htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8') . '"';
                        }
                    }
                }

                return "<h{$level}{$attrs}><a href=\"#{$anchor}\" class=\"heading-anchor\">#</a> {$inner}</h{$level}>";
            },
            $htmlContent
        );

        return [
            'links' => $tocLinks,
            'html' => is_string($replaced) ? $replaced : $htmlContent,
        ];
    }
}
