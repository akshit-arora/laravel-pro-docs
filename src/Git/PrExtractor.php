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
     * Generic short words that must never match on their own (e.g. "add" in
     * "Context::add" must not match every commit mentioning "add").
     *
     * @var array<string>
     */
    private const GENERIC_TERMS = [
        'add', 'get', 'set', 'all', 'new', 'fix', 'use', 'run', 'has', 'put',
        'push', 'pull', 'make', 'create', 'update', 'delete', 'find', 'save',
    ];

    /**
     * Find the best PR number from an array of commit messages, matching specifically
     * against the given symbol, method, class, or command name.
     *
     * Matching is word-boundary aware so short method names (e.g. "add") do not
     * match unrelated commits. The loose "few commits touched this file, take
     * the first PR" fallback now only applies when all file commits agree on a
     * single PR, otherwise it returns null instead of a random PR.
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
            $fullSymbol = $symbol;
            $subTerms = [];
            if (str_contains($symbol, '::')) {
                [$class, $method] = explode('::', $symbol, 2);
                $classShort = $class;
                if (str_contains($class, '\\')) {
                    $parts = explode('\\', $class);
                    $classShort = (string) end($parts);
                }
                $subTerms[] = $method;
                $subTerms[] = $classShort;
            } elseif (str_contains($symbol, ':')) {
                $parts = explode(':', $symbol);
                $last = (string) end($parts);
                if ($last !== '' && $last !== $symbol) {
                    $subTerms[] = $last;
                }
            } elseif (str_starts_with($symbol, '@')) {
                $subTerms[] = substr($symbol, 1);
            }

            // Pass 1: full symbol mention (e.g. "Number::currency"). Substring is
            // fine here because the full symbol is already specific.
            foreach ($commitMessages as $msg) {
                if (stripos($msg, $fullSymbol) !== false) {
                    $pr = $this->extractPrNumber($msg);
                    if ($pr !== null) {
                        return $pr;
                    }
                }
            }

            // Pass 2: method/class term with word boundaries, skipping generic words.
            foreach ($commitMessages as $msg) {
                foreach ($subTerms as $term) {
                    $term = trim($term);
                    if (strlen($term) < 4 || in_array(strtolower($term), self::GENERIC_TERMS, true)) {
                        continue;
                    }
                    if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $msg) === 1) {
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

        // 2. For file-specific commits: only attribute when the file history is
        // unambiguous — i.e. every PR-bearing commit in this range points to the
        // same single PR. Otherwise return null instead of a random PR.
        if (count($commitMessages) <= 3) {
            $prs = [];
            foreach ($commitMessages as $msg) {
                $pr = $this->extractPrNumber($msg);
                if ($pr !== null) {
                    $prs[] = $pr;
                }
            }
            $unique = array_values(array_unique($prs));
            if (count($unique) === 1) {
                return $unique[0];
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
