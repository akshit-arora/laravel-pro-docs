<?php

declare(strict_types=1);

namespace LaravelProDocs\Support;

/**
 * Builds left-nav sidebar HTML from documentation.md.
 * Shared by docs:serve (href="/slug") and docs:build-static (href="slug.html").
 */
final class SidebarBuilder
{
    public static function build(string $docsPath, string $activePage, bool $staticLinks = false): string
    {
        $docIndexFile = rtrim($docsPath, '/\\') . '/documentation.md';
        if (!is_file($docIndexFile)) {
            return self::fallback($docsPath, $activePage, $staticLinks);
        }

        $lines = file($docIndexFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return self::fallback($docsPath, $activePage, $staticLinks);
        }

        $sidebarHtml = '';
        $currentCategory = null;

        foreach ($lines as $line) {
            if (preg_match('/^-\s*##\s*(.*)$/', $line, $catMatch)) {
                if ($currentCategory !== null) {
                    $sidebarHtml .= '</ul></div>';
                }
                $currentCategory = trim($catMatch[1]);
                $sidebarHtml .= sprintf(
                    '<div class="nav-group"><div class="nav-group-title">%s</div><ul class="nav-links">',
                    htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8')
                );
            } elseif (preg_match('/\[([^\]]+)\]\(\/docs\/\{\{version\}\}\/([^\)]+)\)/', $line, $linkMatch)) {
                $linkTitle = $linkMatch[1];
                $linkSlug = $linkMatch[2];
                $isActive = ($linkSlug === $activePage);
                $href = $staticLinks ? $linkSlug . '.html' : '/' . $linkSlug;
                $sidebarHtml .= sprintf(
                    '<li><a href="%s" class="nav-item %s">%s</a></li>',
                    htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
                    $isActive ? 'active' : '',
                    htmlspecialchars($linkTitle, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        if ($currentCategory !== null) {
            $sidebarHtml .= '</ul></div>';
        }

        if ($sidebarHtml === '') {
            return self::fallback($docsPath, $activePage, $staticLinks);
        }

        return $sidebarHtml;
    }

    private static function fallback(string $docsPath, string $activePage, bool $staticLinks): string
    {
        $allDocs = glob(rtrim($docsPath, '/\\') . '/*.md');
        if (!is_array($allDocs)) {
            $allDocs = [];
        }

        $sidebarHtml = '<div class="nav-group"><div class="nav-group-title">Documentation</div><ul class="nav-links">';
        foreach ($allDocs as $docFile) {
            $slug = basename($docFile, '.md');
            $title = ucwords(str_replace('-', ' ', $slug));
            $isActive = ($slug === $activePage);
            $href = $staticLinks ? $slug . '.html' : '/' . $slug;
            $sidebarHtml .= sprintf(
                '<li><a href="%s" class="nav-item %s">%s</a></li>',
                htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
                $isActive ? 'active' : '',
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            );
        }

        return $sidebarHtml . '</ul></div>';
    }
}
