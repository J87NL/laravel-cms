<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PendingRevisionEditor;

class DiscardPendingRevision
{
    public function __construct(
        private readonly PendingRevisionEditor $pending,
    ) {}

    public function handle(Page $page): bool
    {
        return DB::transaction(function () use ($page): bool {
            $locked = Page::query()
                ->lockForUpdate()
                ->findOrFail($page->id);

            return $this->pending->discard($locked);
        });
    }
}
