<?php

declare(strict_types=1);

namespace J87NL\Cms\Sitemap;

use J87NL\Cms\Findability\IndexablePages;

class PageSitemapContributor implements SitemapContributor
{
    public function __construct(
        private readonly IndexablePages $pages,
    ) {}

    public function urls(): iterable
    {
        $pages = $this->pages->all();
        $lastModified = $this->pages->lastModified($pages);

        foreach ($pages as $page) {
            yield new SitemapUrl(url($page->url()), $lastModified[$page->id] ?? null);
        }
    }
}
