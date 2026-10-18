<?php

declare(strict_types=1);

namespace J87NL\Cms\Sitemap;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class SitemapRegistry
{
    /**
     * @var list<SitemapContributor|class-string<SitemapContributor>>
     */
    private array $contributors = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  SitemapContributor|class-string<SitemapContributor>  $contributor
     */
    public function contribute(SitemapContributor|string $contributor): static
    {
        $this->contributors[] = $contributor;

        return $this;
    }

    /**
     * @return list<SitemapContributor>
     */
    public function contributors(): array
    {
        return array_map(function (SitemapContributor|string $contributor): SitemapContributor {
            $instance = is_string($contributor) ? $this->container->make($contributor) : $contributor;

            if (! $instance instanceof SitemapContributor) {
                throw new InvalidArgumentException(sprintf('[%s] does not implement %s.', get_debug_type($instance), SitemapContributor::class));
            }

            return $instance;
        }, $this->contributors);
    }
}
