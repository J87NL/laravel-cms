<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use J87NL\Cms\Models\Block;

class ToggleBlockVisibility
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
    ) {}

    public function handle(Block $block, ?int $userId): void
    {
        DB::transaction(function () use ($block, $userId): void {
            $block->is_visible = ! $block->is_visible;
            $block->save();

            $this->recordRevision->handle($block->page, $userId);
        });
    }
}
