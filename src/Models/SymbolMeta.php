<?php

declare(strict_types=1);

namespace LaravelProDocs\Models;

use JsonSerializable;

final class SymbolMeta implements JsonSerializable
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $version,
        public readonly ?int $pr = null,
        public readonly ?string $prUrl = null,
        public readonly ?string $apiUrl = null,
    ) {
    }

    public static function fromArray(string $symbol, array $data): self
    {
        return new self(
            symbol: $symbol,
            version: $data['version'] ?? '',
            pr: isset($data['pr']) ? (int) $data['pr'] : null,
            prUrl: $data['pr_url'] ?? null,
            apiUrl: $data['api_url'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        $data = [
            'version' => $this->version,
            'pr' => $this->pr,
            'pr_url' => $this->prUrl,
        ];

        if ($this->apiUrl !== null) {
            $data['api_url'] = $this->apiUrl;
        }

        return $data;
    }
}
