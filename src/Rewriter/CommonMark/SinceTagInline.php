<?php

declare(strict_types=1);

namespace LaravelProDocs\Rewriter\CommonMark;

use League\CommonMark\Node\Inline\AbstractInline;

class SinceTagInline extends AbstractInline
{
    public function __construct(
        private string $version,
        private ?int $pr = null,
        private ?string $prUrl = null,
    ) {
        parent::__construct();
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getPr(): ?int
    {
        return $this->pr;
    }

    public function setPr(?int $pr): void
    {
        $this->pr = $pr;
    }

    public function getPrUrl(): ?string
    {
        return $this->prUrl;
    }

    public function setPrUrl(?string $prUrl): void
    {
        $this->prUrl = $prUrl;
    }

    public function toHtml(): string
    {
        if ($this->pr !== null && $this->prUrl !== null) {
            return sprintf('<x-since v="%s" pr="%d" url="%s" />', $this->version, $this->pr, $this->prUrl);
        }

        return sprintf('<x-since v="%s" />', $this->version);
    }
}
