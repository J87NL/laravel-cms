<?php

declare(strict_types=1);

namespace J87NL\Cms;

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Sitemap\SitemapRegistry;
use Laravel\Mcp\Server\Tool;

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

    /**
     * @return list<class-string<Tool>>
     */
    public function mcpTools(): array
    {
        return CmsTools::all();
    }

    public function mcpInstructions(): string
    {
        return CmsTools::INSTRUCTIONS;
    }
}
