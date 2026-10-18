<?php

declare(strict_types=1);

namespace J87NL\Cms\Facades;

use Illuminate\Support\Facades\Facade;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Sitemap\SitemapRegistry;

/**
 * @method static BlockRegistry blocks()
 * @method static SitemapRegistry sitemap()
 *
 * @see \J87NL\Cms\Cms
 */
class Cms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \J87NL\Cms\Cms::class;
    }
}
