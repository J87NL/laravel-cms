<?php

declare(strict_types=1);

namespace J87NL\Cms\Console;

use Illuminate\Console\Command;
use J87NL\Cms\Cache\PageCache;

class FlushPageCacheCommand extends Command
{
    protected $signature = 'cms:flush-page-cache';

    protected $description = 'Drop every cached public page, for example after a deploy changed the layout';

    public function handle(PageCache $cache): int
    {
        $cache->flush();

        $this->info('The public page cache has been flushed.');

        return self::SUCCESS;
    }
}
