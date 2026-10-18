<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use J87NL\Cms\Blocks\BlockType;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;

class SaveBlock
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Page $page, Block $block, BlockType $type, array $data, ?int $userId): Block
    {
        return DB::transaction(function () use ($page, $block, $type, $data, $userId): Block {
            if (! $block->exists) {
                $block->page()->associate($page);
                $block->sort_order = $this->nextSortOrder($page);
            }

            $block->type = $type->key();
            $block->data = $type->prepare($data);
            $block->save();

            $this->recordRevision->handle($page, $userId);

            return $block;
        });
    }

    private function nextSortOrder(Page $page): int
    {
        $max = Block::query()
            ->where('page_id', $page->id)
            ->max('sort_order');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
