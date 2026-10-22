<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;

class RestoreRevision
{
    public function __construct(
        private readonly SavePage $savePage,
        private readonly RecordRevision $recordRevision,
    ) {}

    public function handle(Revision $revision, ?int $userId): Page
    {
        if ($revision->is_pending) {
            throw ValidationException::withMessages([
                'revision' => __('cms::validation.restore_pending'),
            ]);
        }

        return DB::transaction(function () use ($revision, $userId): Page {
            $page = $revision->page;
            $snapshot = $revision->snapshot;

            $this->savePage->apply($page, $this->pageAttributes($page, $snapshot['page']));

            Block::query()
                ->where('page_id', $page->id)
                ->delete();

            foreach ($snapshot['blocks'] as $blockSnapshot) {
                $block = new Block([
                    'type' => (string) ($blockSnapshot['type'] ?? ''),
                    'data' => is_array($blockSnapshot['data'] ?? null) ? $blockSnapshot['data'] : [],
                    'sort_order' => (int) ($blockSnapshot['sort_order'] ?? 0),
                    'is_visible' => (bool) ($blockSnapshot['is_visible'] ?? true),
                ]);

                $block->page()->associate($page);
                $block->save();
            }

            $this->recordRevision->handle($page, $userId);

            return $page;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function pageAttributes(Page $page, array $snapshot): array
    {
        $attributes = Arr::only($snapshot, Page::CONTENT_ATTRIBUTES);

        $parentId = $attributes['parent_id'] ?? null;

        $parentExists = ! is_null($parentId) && Page::query()
            ->whereKey($parentId)
            ->exists();

        if (! is_null($parentId) && ! $parentExists) {
            $attributes['parent_id'] = $page->parent_id;
        }

        return $attributes;
    }
}
