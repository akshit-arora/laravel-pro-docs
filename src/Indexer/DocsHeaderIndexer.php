<?php

declare(strict_types=1);

namespace LaravelProDocs\Indexer;

use LaravelProDocs\Models\SymbolMeta;
use RuntimeException;

class DocsHeaderIndexer
{
    private const BRANCHES = [
        '4.0', '4.1', '4.2', '5.0', '5.1', '5.2', '5.3', '5.4', '5.5', '5.6', '5.7', '5.8',
        '6.x', '7.x', '8.x', '9.x', '10.x', '11.x', '12.x', '13.x'
    ];

    private const GENERIC_HEADINGS = [
        'introduction', 'how it works', 'configuration', 'driver prerequisites', 'prerequisites',
        'overview', 'basic usage', 'usage', 'getting started', 'installation',
        'directory configuration', 'available methods', 'methods',
    ];

    public function __construct(
        private readonly string $docsRepoPath,
        private readonly ?string $frameworkRepoPath = null,
    ) {
        if (!is_dir($this->docsRepoPath) || !is_dir($this->docsRepoPath . '/.git')) {
            throw new RuntimeException("Invalid git repository at: {$this->docsRepoPath}");
        }
    }

    /**
     * Index page and section heading introductions across docs branches.
     *
     * @param callable(string $file, int $current, int $total): void|null $progressCallback
     * @return array<string, SymbolMeta>
     */
    public function indexHeaders(?callable $progressCallback = null): array
    {
        $files = glob(rtrim($this->docsRepoPath, '/\\') . '/*.md');
        if (empty($files)) {
            return [];
        }

        // Pre-cache all branch markdown files in-memory for ultra-fast scanning.
        // For each branch file we store both raw content and the normalized
        // heading set, so section matching compares headings-to-headings
        // instead of substring-searching the whole file (which falsely matched
        // any prose mentioning the words, e.g. "cache").
        $branchCache = [];
        $branchHeadings = [];
        foreach (self::BRANCHES as $branch) {
            $tree = $this->runGit($this->docsRepoPath, ['ls-tree', '-r', '--name-only', "origin/{$branch}"]);
            if ($tree === '') {
                continue;
            }

            $treeFiles = array_filter(explode("\n", trim($tree)));
            foreach ($treeFiles as $treeFile) {
                if (str_ends_with($treeFile, '.md')) {
                    $content = $this->runGit($this->docsRepoPath, ['show', "origin/{$branch}:{$treeFile}"]);
                    $branchCache[$branch][$treeFile] = $content;
                    $branchHeadings[$branch][$treeFile] = self::extractNormalizedHeadings($content);
                }
            }
        }

        $total = count($files);
        $headerIndex = [];

        foreach ($files as $index => $filePath) {
            $filename = basename($filePath);
            $slug = basename($filePath, '.md');
            $content = file_get_contents($filePath);
            if ($content === false) {
                continue;
            }

            // 1. Page level introduction (H1)
            $earliestFileBranch = null;
            foreach (self::BRANCHES as $branch) {
                if (isset($branchCache[$branch][$filename])) {
                    $earliestFileBranch = $branch;
                    break;
                }
            }

            if ($earliestFileBranch !== null && in_array($earliestFileBranch, ['10.x', '11.x', '12.x', '13.x'], true)) {
                $headerIndex["page:{$slug}"] = new SymbolMeta(
                    symbol: "page:{$slug}",
                    version: "v{$earliestFileBranch}",
                    pr: null,
                    prUrl: null,
                );
            }

            // 2. Section headings (H2, H3, H4)
            if (preg_match_all('/^(#{1,4}\s+[^<\n]+)/m', $content, $hMatches)) {
                foreach ($hMatches[1] as $headerLine) {
                    $cleanHeader = trim($headerLine);
                    $hashStripped = preg_replace('/^#{1,4}\s+/', '', $cleanHeader);
                    $titleOnly = trim(is_string($hashStripped) ? $hashStripped : $cleanHeader);
                    $braceStripped = preg_replace('/\{\s*\.[^}]+\}/', '', $titleOnly);
                    $titleStripped = trim(is_string($braceStripped) ? $braceStripped : $titleOnly);
                    $cleanTitleOnly = trim($titleStripped, '` ');
                    $spaceNormalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $cleanTitleOnly);
                    $normalizedTitle = strtolower(trim(is_string($spaceNormalized) ? $spaceNormalized : $cleanTitleOnly));

                    // Skip generic boilerplate headings
                    if ($normalizedTitle === '' || in_array($normalizedTitle, self::GENERIC_HEADINGS, true)) {
                        continue;
                    }

                    $earliestHBranch = null;

                    foreach (self::BRANCHES as $branch) {
                        if (isset($branchHeadings[$branch][$filename])) {
                            $headings = $branchHeadings[$branch][$filename];
                            if (isset($headings[$normalizedTitle])) {
                                $earliestHBranch = $branch;
                                break;
                            }
                        }
                    }

                    // Only index modern section introductions (v10.x, v11.x, v12.x, v13.x)
                    if ($earliestHBranch !== null && in_array($earliestHBranch, ['10.x', '11.x', '12.x', '13.x'], true)) {
                        $meta = new SymbolMeta(
                            symbol: "header:{$slug}:{$titleOnly}",
                            version: "v{$earliestHBranch}",
                            pr: null,
                            prUrl: null,
                        );

                        $headerIndex["header:{$slug}:{$titleOnly}"] = $meta;
                        $headerIndex["header:{$slug}:{$cleanTitleOnly}"] = $meta;
                        if ($titleStripped !== $titleOnly) {
                            $headerIndex["header:{$slug}:{$titleStripped}"] = $meta;
                        }
                    }
                }
            }

            if ($progressCallback !== null) {
                $progressCallback($slug, $index + 1, $total);
            }
        }

        return $headerIndex;
    }

    /**
     * Extract normalized heading titles (H1-H4) from markdown content.
     *
     * @return array<string, true> Set of normalized heading => true
     */
    private static function extractNormalizedHeadings(string $content): array
    {
        $headings = [];
        if (preg_match_all('/^(#{1,4}\s+[^<\n]+)/m', $content, $hMatches) !== false) {
            foreach ($hMatches[1] as $headerLine) {
                $title = trim((string) preg_replace('/^#{1,4}\s+/', '', trim($headerLine)));
                $title = trim((string) preg_replace('/\{\s*\.[^}]+\}/', '', $title));
                $title = trim($title, '` ');
                $normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', ' ', $title)));
                if ($normalized !== '') {
                    $headings[$normalized] = true;
                }
            }
        }

        return $headings;
    }

    /**
     * Run a git command safely in a repository directory.
     *
     * @param array<string> $args
     */
    private function runGit(string $repoPath, array $args): string
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $command = sprintf('git -C %s %s 2>/dev/null', escapeshellarg($repoPath), implode(' ', $escapedArgs));

        $output = shell_exec($command);
        return is_string($output) ? trim($output) : '';
    }
}
