<?php

declare(strict_types=1);

namespace J87NL\Cms\Sitemap;

use Carbon\CarbonInterface;

final readonly class SitemapUrl
{
    public function __construct(
        public string $loc,
        public ?CarbonInterface $lastmod = null,
    ) {}
}
