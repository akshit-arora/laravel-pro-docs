<?php

declare(strict_types=1);

namespace LaravelProDocs\Git;

class PrExtractor
{
    private const GITHUB_REPO_URL = 'https://github.com/laravel/framework/pull';

    /**
     * Regex patterns ordered by specificity to extract GitHub PR number from commit messages.
     *
     * @var array<string>
     */
    private const PATTERNS = [
        // Merge pull request #49451 from laravel/...
        '/Merge pull request #(\d+)/i',
        // [10.x] Add Number::currency (#49451)
        '/\(#(\d+)\)/',
        // Fixes #49451, Closes #49451, Resolved #49451
        '/(?:close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved)\s+#(\d+)/i',
        // https://github.com/laravel/framework/pull/49451
        '/github\.com\/[^\/]+\/[^\/]+\/pull\/(\d+)/i',
        // Pull request #49451
        '/pull request #(\d+)/i',
    ];

    /**
     * Extract the first matching PR number from a single commit message or text block.
     */
    public function extractPrNumber(string $message): ?int
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Extract all unique PR numbers mentioned in a commit message or text block.
     *
     * @return array<int>
     */
    public function extractAllPrNumbers(string $message): array
    {
        $prs = [];
        foreach (self::PATTERNS as $pattern) {
            if (preg_match_all($pattern, $message, $matches)) {
                foreach ($matches[1] as $pr) {
                    $prs[] = (int) $pr;
                }
            }
        }

        return array_values(array_unique($prs));
    }

    /**
     * Find the best PR number from an array of commit messages, matching specifically
     * against the given symbol, method, class, or command name.
     *
     * @param array<string> $commitMessages
     */
    public function findPrForSymbol(array $commitMessages, ?string $symbol = null, bool $strictMatchOnly = false): ?int
    {
        if (empty($commitMessages)) {
            return null;
        }

        // 1. If symbol is given, check for commits mentioning the symbol, method, class, or command
        if ($symbol !== null) {
            $terms = [$symbol];
            if (str_contains($symbol, '::')) {
                [$class, $method] = explode('::', $symbol, 2);
                $terms[] = $method;
                $terms[] = $class;
            } elseif (str_contains($symbol, ':')) {
                $parts = explode(':', $symbol);
                $terms[] = end($parts);
                $terms[] = $symbol;
            } elseif (str_starts_with($symbol, '@')) {
                $terms[] = substr($symbol, 1);
            }

            foreach ($commitMessages as $msg) {
                foreach ($terms as $term) {
                    if (strlen($term) >= 3 && stripos($msg, $term) !== false) {
                        $pr = $this->extractPrNumber($msg);
                        if ($pr !== null) {
                            return $pr;
                        }
                    }
                }
            }
        }

        // If strict matching is required (e.g. range-wide commits across entire repo), do not grab random PRs
        if ($strictMatchOnly) {
            return null;
        }

        // 2. For file-specific commits (commits that modified this exact file in this release):
        // If only 1 or 2 commits touched the file and contain a PR, associate with it
        if (count($commitMessages) <= 3) {
            foreach ($commitMessages as $msg) {
                $pr = $this->extractPrNumber($msg);
                if ($pr !== null) {
                    return $pr;
                }
            }
        }

        return null;
    }

    public function generatePrUrl(?int $pr): ?string
    {
        if ($pr === null) {
            return null;
        }

        return sprintf('%s/%d', self::GITHUB_REPO_URL, $pr);
    }

    public function generateUrl(?int $pr, ?string $version = null): ?string
    {
        if ($pr !== null) {
            return sprintf('%s/%d', self::GITHUB_REPO_URL, $pr);
        }

        if ($version !== null && $version !== '') {
            $cleanTag = ltrim($version, 'v');
            return sprintf('https://github.com/laravel/framework/releases/tag/v%s', $cleanTag);
        }

        return null;
    }
}
