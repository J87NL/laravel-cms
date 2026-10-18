<?php

declare(strict_types=1);

namespace J87NL\Cms\Cache;

use Illuminate\Support\Facades\DB;

class FlushPageCache
{
    public function __construct(
        private readonly PageCache $cache,
    ) {}

    public function saved(): void
    {
        $this->flush();
    }

    public function deleted(): void
    {
        $this->flush();
    }

    private function flush(): void
    {
        DB::afterCommit(fn () => $this->cache->flush());
    }
}
