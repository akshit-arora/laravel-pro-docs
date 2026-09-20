<?php

declare(strict_types=1);

namespace LaravelProDocs\Indexer;

use LaravelProDocs\Git\GitRepository;
use LaravelProDocs\Git\PrExtractor;
use RuntimeException;

class SymbolIndexer
{
    public function __construct(
        private readonly GitRepository $git,
        private readonly AstSymbolExtractor $extractor = new AstSymbolExtractor(),
        private readonly PrExtractor $prExtractor = new PrExtractor(),
        private readonly SymbolRegistry $registry = new SymbolRegistry(),
    ) {
    }

    public function getRegistry(): SymbolRegistry
    {
        return $this->registry;
    }

    /**
     * Build the symbol index across Git tags.
     *
     * @param callable(string $tag, int $currentIndex, int $totalTags, int $newSymbolsCount): void|null $progressCallback
     */
    public function buildIndex(
        string $fromTag = 'v9.0.0',
        ?string $toTag = null,
        ?callable $progressCallback = null,
    ): SymbolRegistry {
        $tags = $this->git->getTags('v*.*.*', $fromTag, $toTag);
        if (empty($tags)) {
            throw new RuntimeException("No matching git tags found starting from {$fromTag}");
        }

        $totalTags = count($tags);
        $prevTag = null;

        foreach ($tags as $index => $tag) {
            $initialCount = $this->registry->count();

            if ($prevTag === null) {
                // Base release: parse all files in src/
                $this->indexBaseRelease($tag);
            } else {
                // Subsequent release: parse diff against previous tag
                $this->indexReleaseDiff($prevTag, $tag);
            }

            $newSymbols = $this->registry->count() - $initialCount;

            if ($progressCallback !== null) {
                $progressCallback($tag, $index + 1, $totalTags, $newSymbols);
            }

            $prevTag = $tag;
        }

        return $this->registry;
    }

    private function indexBaseRelease(string $tag): void
    {
        $files = $this->git->listFiles($tag, 'src/');
        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $content = $this->git->getFileContent($tag, $file);
            if ($content === null || trim($content) === '') {
                continue;
            }

            $symbols = $this->extractor->extractSymbolsFromCode($content, $file, $tag);
            foreach ($symbols as $symbol => $apiUrl) {
                $releaseUrl = $this->prExtractor->generateUrl(null, $tag);
                $this->registry->register($symbol, $tag, null, $releaseUrl, $apiUrl);
            }
        }
    }

    private function indexReleaseDiff(string $prevTag, string $tag): void
    {
        $changedFiles = $this->git->getChangedFiles($prevTag, $tag, 'src/');
        if (empty($changedFiles)) {
            return;
        }

        $rangeCommits = $this->git->getCommitMessagesInRange($prevTag, $tag);

        foreach ($changedFiles as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $content = $this->git->getFileContent($tag, $file);
            if ($content === null || trim($content) === '') {
                continue;
            }

            $symbols = $this->extractor->extractSymbolsFromCode($content, $file, $tag);
            $newSymbolsInFile = [];

            foreach ($symbols as $symbol => $apiUrl) {
                if (!$this->registry->has($symbol)) {
                    $newSymbolsInFile[$symbol] = $apiUrl;
                }
            }

            if (empty($newSymbolsInFile)) {
                continue;
            }

            $fileCommits = $this->git->getCommitMessagesForFile($prevTag, $tag, $file);

            foreach ($newSymbolsInFile as $symbol => $apiUrl) {
                // 1. Find PR from commits that touched this specific file in this release
                $pr = $this->prExtractor->findPrForSymbol($fileCommits, $symbol, false);

                // 2. If not found, strictly search release-wide commits only if they explicitly mention the symbol/method/class
                if ($pr === null) {
                    $pr = $this->prExtractor->findPrForSymbol($rangeCommits, $symbol, true);
                }

                $prUrl = $this->prExtractor->generateUrl($pr, $tag);
                $this->registry->register($symbol, $tag, $pr, $prUrl, $apiUrl);
            }
        }
    }
}
