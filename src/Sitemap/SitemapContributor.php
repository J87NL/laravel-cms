<?php

declare(strict_types=1);

namespace J87NL\Cms\Sitemap;

interface SitemapContributor
{
    /**
     * @return iterable<SitemapUrl>
     */
    public function urls(): iterable;
}
