<?php

declare(strict_types=1);

namespace LaravelProDocs\Git;

use RuntimeException;

class GitRepository
{
    public function __construct(
        private readonly string $repoPath,
    ) {
        if (!is_dir($this->repoPath) || !is_dir($this->repoPath . '/.git')) {
            throw new RuntimeException("Invalid git repository at: {$this->repoPath}");
        }
    }

    /**
     * Get all version tags sorted chronologically / semver-wise.
     *
     * @return array<string>
     */
    public function getTags(string $pattern = 'v*.*.*', string $fromTag = 'v9.0.0', ?string $toTag = null): array
    {
        $output = $this->runGit(['tag', '-l', $pattern]);
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        // Filter valid release tags (e.g. v9.0.0, v10.38.0, v11.0.0 - ignore -RC, -beta, -alpha)
        $tags = array_filter($lines, function ($tag) {
            return preg_match('/^v\d+\.\d+\.\d+$/', $tag) === 1;
        });

        // Sort by version_compare (stripping 'v' for comparison)
        usort($tags, function (string $a, string $b) {
            $verA = ltrim($a, 'v');
            $verB = ltrim($b, 'v');
            return version_compare($verA, $verB);
        });

        $fromVersion = ltrim($fromTag, 'v');
        $toVersion = $toTag !== null ? ltrim($toTag, 'v') : null;

        $filtered = [];
        foreach ($tags as $tag) {
            $ver = ltrim($tag, 'v');
            if (version_compare($ver, $fromVersion, '<')) {
                continue;
            }
            if ($toVersion !== null && version_compare($ver, $toVersion, '>')) {
                continue;
            }
            $filtered[] = $tag;
        }

        return array_values($filtered);
    }

    /**
     * List all files in a specific directory at a specific tag.
     *
     * @return array<string>
     */
    public function listFiles(string $tag, string $path = 'src/'): array
    {
        try {
            $output = $this->runGit(['ls-tree', '-r', '--name-only', $tag, $path]);
            return array_values(array_filter(array_map('trim', explode("\n", $output))));
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Get file content at a specific tag.
     */
    public function getFileContent(string $tag, string $filePath): ?string
    {
        try {
            return $this->runGit(['show', sprintf('%s:%s', $tag, $filePath)]);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Get modified or added files between two tags.
     *
     * @return array<string>
     */
    public function getChangedFiles(string $prevTag, string $tag, string $path = 'src/'): array
    {
        try {
            $output = $this->runGit(['diff', '--name-only', '--diff-filter=d', $prevTag, $tag, '--', $path]);
            return array_values(array_filter(array_map('trim', explode("\n", $output))));
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Get commit messages touching a specific file in a tag range.
     *
     * @return array<string>
     */
    public function getCommitMessagesForFile(string $prevTag, string $tag, string $filePath): array
    {
        try {
            $output = $this->runGit([
                'log',
                sprintf('%s..%s', $prevTag, $tag),
                '--format=%s%n%b---COMMIT_SEP---',
                '--',
                $filePath,
            ]);

            return array_values(array_filter(array_map('trim', explode('---COMMIT_SEP---', $output))));
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Get all commit messages in a tag range.
     *
     * @return array<string>
     */
    public function getCommitMessagesInRange(string $prevTag, string $tag): array
    {
        try {
            $output = $this->runGit([
                'log',
                sprintf('%s..%s', $prevTag, $tag),
                '--format=%s%n%b---COMMIT_SEP---',
            ]);

            return array_values(array_filter(array_map('trim', explode('---COMMIT_SEP---', $output))));
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * Run a git command safely in the repo directory.
     *
     * @param array<string> $args
     */
    private function runGit(array $args): string
    {
        $escapedArgs = array_map('escapeshellarg', $args);
        $command = sprintf('git -C %s %s', escapeshellarg($this->repoPath), implode(' ', $escapedArgs));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to execute command: {$command}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException("Git command failed (exit code {$exitCode}): {$stderr}");
        }

        return (string) $stdout;
    }
}
