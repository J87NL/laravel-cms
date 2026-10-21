<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;

class ReorderBlocks
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
    ) {}

    /**
     * @param  list<int>  $blockIds
     */
    public function handle(Page $page, array $blockIds, ?int $userId): void
    {
        DB::transaction(function () use ($page, $blockIds, $userId): void {
            $blocks = Block::query()
                ->where('page_id', $page->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $orderedIds = array_map('intval', $blocks->keys()->all());
            $currentIds = $orderedIds;
            $requestedIds = array_values($blockIds);

            sort($currentIds);
            sort($requestedIds);

            if ($requestedIds !== $currentIds) {
                throw ValidationException::withMessages([
                    'block_ids' => __('cms::validation.block_order_incomplete'),
                ]);
            }

            if (array_values($blockIds) === $orderedIds) {
                return;
            }

            foreach (array_values($blockIds) as $position => $id) {
                $block = $blocks->get($id);
                $block->sort_order = $position + 1;

                if ($block->isDirty('sort_order')) {
                    $block->save();
                }
            }

            $this->recordRevision->handle($page, $userId);
        });
    }

    public function move(Page $page, int $blockId, int $toBlockId, ?int $userId): void
    {
        if ($blockId === $toBlockId) {
            return;
        }

        $ids = array_map('intval', Block::query()
            ->where('page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all());

        $from = array_search($blockId, $ids, true);
        $to = array_search($toBlockId, $ids, true);

        if ($from === false || $to === false) {
            throw new InvalidArgumentException('Both blocks must belong to the page that is reordered.');
        }

        array_splice($ids, $from, 1);
        array_splice($ids, $to, 0, [$blockId]);

        $this->handle($page, $ids, $userId);
    }
}
