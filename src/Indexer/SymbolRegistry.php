<?php

declare(strict_types=1);

namespace LaravelProDocs\Indexer;

use LaravelProDocs\Models\SymbolMeta;
use RuntimeException;

class SymbolRegistry
{
    /**
     * @var array<string, SymbolMeta>
     */
    private array $symbols = [];

    public function register(string $symbol, string $version, ?int $pr = null, ?string $prUrl = null, ?string $apiUrl = null): bool
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return false;
        }

        // If symbol already exists, retain the earliest registered version
        if (isset($this->symbols[$symbol])) {
            return false;
        }

        $this->symbols[$symbol] = new SymbolMeta(
            symbol: $symbol,
            version: $version,
            pr: $pr,
            prUrl: $prUrl,
            apiUrl: $apiUrl,
        );

        return true;
    }

    public function updateApiUrl(string $symbol, ?string $apiUrl): void
    {
        $symbol = trim($symbol);
        if ($symbol === '' || !isset($this->symbols[$symbol]) || $apiUrl === null) {
            return;
        }

        $existing = $this->symbols[$symbol];
        $this->symbols[$symbol] = new SymbolMeta(
            symbol: $existing->symbol,
            version: $existing->version,
            pr: $existing->pr,
            prUrl: $existing->prUrl,
            apiUrl: $apiUrl,
        );
    }

    public function get(string $symbol): ?SymbolMeta
    {
        return $this->symbols[$symbol] ?? null;
    }

    public function has(string $symbol): bool
    {
        return isset($this->symbols[$symbol]);
    }

    /**
     * @return array<string, SymbolMeta>
     */
    public function all(): array
    {
        return $this->symbols;
    }

    public function count(): int
    {
        return count($this->symbols);
    }

    public function saveToJson(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }

        // Sort keys alphabetically for clean diffs
        ksort($this->symbols);

        $data = [];
        foreach ($this->symbols as $symbol => $meta) {
            $data[$symbol] = $meta->jsonSerialize();
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to encode symbols index to JSON");
        }

        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write symbols index to: {$filePath}");
        }
    }

    public static function loadFromJson(string $filePath): self
    {
        $registry = new self();
        if (!file_exists($filePath)) {
            return $registry;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Failed to read symbols index from: {$filePath}");
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON format in symbols index: {$filePath}");
        }

        foreach ($data as $symbol => $meta) {
            if (is_array($meta)) {
                $registry->symbols[$symbol] = SymbolMeta::fromArray((string) $symbol, $meta);
            }
        }

        return $registry;
    }
}
