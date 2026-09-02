<?php

declare(strict_types=1);

namespace LaravelProDocs\Rewriter\CommonMark;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\Config\ConfigurationBuilderInterface;
use Nette\Schema\Expect;

class SinceTagExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('since_tag', Expect::structure([
            'tag_name' => Expect::string('x-since'),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(SinceTagInline::class, new SinceTagRenderer());
    }
}
