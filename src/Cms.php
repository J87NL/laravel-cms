<?php

declare(strict_types=1);

namespace J87NL\Cms;

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Sitemap\SitemapRegistry;

class Cms
{
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly SitemapRegistry $sitemap,
    ) {}

    public function blocks(): BlockRegistry
    {
        return $this->blocks;
    }

    public function sitemap(): SitemapRegistry
    {
        return $this->sitemap;
    }
}
