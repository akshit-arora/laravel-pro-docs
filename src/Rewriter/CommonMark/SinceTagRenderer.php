<?php

declare(strict_types=1);

namespace LaravelProDocs\Rewriter\CommonMark;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use InvalidArgumentException;

class SinceTagRenderer implements NodeRendererInterface
{
    /**
     * @param SinceTagInline $node
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if (!$node instanceof SinceTagInline) {
            throw new InvalidArgumentException('Incompatible node type: ' . get_class($node));
        }

        return $node->toHtml();
    }
}
