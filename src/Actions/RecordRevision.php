<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;

class RecordRevision
{
    public function handle(Page $page, ?int $userId): Revision
    {
        $revision = new Revision([
            'snapshot' => $this->snapshot($page),
            'created_by' => $userId,
        ]);

        $revision->page()->associate($page);
        $revision->save();

        $this->prune($page);

        return $revision;
    }

    /**
     * @return array{page: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    public function snapshot(Page $page): array
    {
        $blocks = Block::query()
            ->where('page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'page' => [
                ...$page->only(Page::CONTENT_ATTRIBUTES),
                'status' => $page->status->value,
                'is_home' => $page->is_home,
            ],
            'blocks' => array_values($blocks
                ->map(fn (Block $block): array => [
                    'type' => $block->type,
                    'data' => $block->data,
                    'sort_order' => $block->sort_order,
                    'is_visible' => $block->is_visible,
                ])
                ->all()),
        ];
    }

    private function prune(Page $page): void
    {
        $keep = max(1, (int) config('cms.revisions.keep', 20));

        $oldestKeptId = Revision::query()
            ->where('page_id', $page->id)
            ->orderByDesc('id')
            ->skip($keep - 1)
            ->value('id');

        if (is_null($oldestKeptId)) {
            return;
        }

        Revision::query()
            ->where('page_id', $page->id)
            ->where('id', '<', $oldestKeptId)
            ->delete();
    }
}
