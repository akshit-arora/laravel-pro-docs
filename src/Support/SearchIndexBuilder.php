<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

/**
 * Builds the search dataset shared by docs:serve (/api/search.json)
 * and docs:build-static (api/search.json).
 *
 * @return array<int, array<string, mixed>>
 */
final class SearchIndexBuilder
{
    /**
     * @param array<string, array<string, mixed>>|null $symbolsData
     * @param array<string, mixed> $symbolPages Map of symbol => ['page' => slug, 'anchor' => ?slug] (new format)
     *   or symbol => list of slugs (legacy format)
     * @return array<int, array<string, mixed>>
     */
    public static function build(string $docsPath, ?array $symbolsData, bool $staticUrls = false, array $symbolPages = []): array
    {
        $results = [];
        $allDocs = glob(rtrim($docsPath, '/\\') . '/*.md');
        if (!is_array($allDocs)) {
            $allDocs = [];
        }

        foreach ($allDocs as $docFile) {
            $slug = basename($docFile, '.md');
            if ($slug === 'documentation') {
                continue;
            }
            $results[] = [
                'type' => 'page',
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'url' => $staticUrls ? $slug . '.html' : '/' . $slug,
            ];
        }

        if (is_array($symbolsData)) {
            foreach ($symbolsData as $symbol => $meta) {
                if (!is_string($symbol)) {
                    continue;
                }
                if (str_starts_with($symbol, 'page:') || str_starts_with($symbol, 'header:')) {
                    continue;
                }
                // Exclude verbose FQCNs to keep the search dataset compact.
                if (str_contains($symbol, '\\')) {
                    continue;
                }
                if (!is_array($meta)) {
                    continue;
                }

                // Row clicks deep-link to the documenting page + section.
                // API/PR links stay on their badges.
                [$docSlug, $docAnchor] = self::primaryPage($symbolPages[$symbol] ?? null);
                $url = null;
                if ($docSlug !== null) {
                    $url = $staticUrls ? $docSlug . '.html' : '/' . $docSlug;
                    if ($docAnchor !== null) {
                        $url .= '#' . $docAnchor;
                    }
                }
                $results[] = [
                    'type' => 'symbol',
                    'title' => $symbol,
                    'version' => $meta['version'] ?? '',
                    'pr' => $meta['pr'] ?? null,
                    'pr_url' => $meta['pr_url'] ?? null,
                    'api_url' => $meta['api_url'] ?? null,
                    'url' => $url,
                ];
            }
        }

        return $results;
    }

    /**
     * Resolve the primary docs page + section anchor for a symbol map entry.
     * Supports the current {page, anchor, pages} format and the legacy
     * slug-list format.
     *
     * @param mixed $entry
     * @return array{0: ?string, 1: ?string} [slug, anchor]
     */
    private static function primaryPage(mixed $entry): array
    {
        if (is_array($entry)) {
            $page = $entry['page'] ?? null;
            if (is_string($page) && $page !== '') {
                $anchor = $entry['anchor'] ?? null;

                return [$page, is_string($anchor) && $anchor !== '' ? $anchor : null];
            }
            // Legacy: plain list of slugs.
            foreach ($entry as $slug) {
                if (is_string($slug) && $slug !== '') {
                    return [$slug, null];
                }
            }
        }

        return [null, null];
    }

    /**
     * Load the symbol => docs-pages map written by docs:rewrite
     * (symbol_pages.json next to the rewritten markdown).
     *
     * @return array<string, mixed>
     */
    public static function loadSymbolPages(string $docsPath): array
    {
        $path = rtrim($docsPath, '/\\') . DIRECTORY_SEPARATOR . 'symbol_pages.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    public static function loadSymbolsData(string $symbolsIndexPath): ?array
    {
        if (!is_file($symbolsIndexPath)) {
            return null;
        }

        $raw = file_get_contents($symbolsIndexPath);
        if (!is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
