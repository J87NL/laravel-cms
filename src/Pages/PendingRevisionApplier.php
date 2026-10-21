<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use J87NL\Cms\Actions\RecordRevision;
use J87NL\Cms\Actions\RestoreRevision;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;

class PendingRevisionApplier
{
    public function __construct(
        private readonly SavePage $savePage,
        private readonly RestoreRevision $restoreRevision,
        private readonly RecordRevision $recordRevision,
    ) {}

    public function apply(Page $page, Revision $revision): void
    {
        $content = PendingRevisionEditor::content($revision->snapshot);
        $base = PendingRevisionEditor::base($revision->snapshot) ?? $this->recordRevision->snapshot($page);

        $this->applyPage($page, $content['page'], $base['page']);
        $this->applyBlocks($page, $content['blocks'], $base['blocks']);

        $revision->delete();
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $base
     */
    private function applyPage(Page $page, array $pending, array $base): void
    {
        $changes = [];

        foreach (Page::CONTENT_ATTRIBUTES as $field) {
            if (! array_key_exists($field, $pending)) {
                continue;
            }

            if (json_encode($pending[$field]) === json_encode($base[$field] ?? null)) {
                continue;
            }

            $changes[$field] = $pending[$field];
        }

        if ($changes === []) {
            return;
        }

        try {
            $this->savePage->apply($page, $this->restoreRevision->pageAttributes($page, $changes));
        } catch (UniqueConstraintViolationException) {
            throw SavePage::pathTaken($page);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $base
     */
    private function applyBlocks(Page $page, array $pending, array $base): void
    {
        $live = Block::query()
            ->where('page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $baseById = $this->byId($base);
        $pendingById = $this->byId($pending);

        foreach (array_diff_key($baseById, $pendingById) as $id => $block) {
            $live->get($id)?->delete();
            $live->forget($id);
        }

        $pendingIds = [];

        foreach ($this->sorted($pending) as $block) {
            $id = (int) ($block['id'] ?? 0);
            $baseBlock = $baseById[$id] ?? null;
            $liveBlock = $live->get($id);

            if (! is_null($baseBlock) && ! $this->differs($block, $baseBlock)) {
                if (! is_null($liveBlock)) {
                    $pendingIds[] = $liveBlock->id;
                }

                continue;
            }

            $saved = $liveBlock ?? new Block;

            $saved->fill([
                'type' => (string) ($block['type'] ?? ''),
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
                'is_visible' => (bool) ($block['is_visible'] ?? true),
            ]);

            if (! $saved->exists) {
                $saved->page()->associate($page);
                $saved->sort_order = (int) ($block['sort_order'] ?? 0);
            }

            $saved->save();

            $live->put($saved->id, $saved);
            $pendingIds[] = $saved->id;
        }

        if ($this->order($pending) === $this->order($base)) {
            return;
        }

        $this->reorder($live, $pendingIds);
    }

    /**
     * @param  Collection<int, Block>  $live
     * @param  list<int>  $pendingIds
     */
    private function reorder(Collection $live, array $pendingIds): void
    {
        $others = array_values(array_diff(array_map('intval', $live->keys()->all()), $pendingIds));

        foreach ([...$pendingIds, ...$others] as $position => $id) {
            $block = $live->get($id);

            if (is_null($block)) {
                continue;
            }

            $block->sort_order = $position + 1;

            if ($block->isDirty('sort_order')) {
                $block->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $base
     */
    private function differs(array $pending, array $base): bool
    {
        return json_encode([
            $pending['type'] ?? null,
            $pending['data'] ?? null,
            (bool) ($pending['is_visible'] ?? true),
        ]) !== json_encode([
            $base['type'] ?? null,
            $base['data'] ?? null,
            (bool) ($base['is_visible'] ?? true),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $blocks): array
    {
        $byId = [];

        foreach ($blocks as $block) {
            $byId[(int) ($block['id'] ?? 0)] = $block;
        }

        return $byId;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function sorted(array $blocks): array
    {
        usort($blocks, fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        return $blocks;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<int>
     */
    private function order(array $blocks): array
    {
        return array_map(fn (array $block): int => (int) ($block['id'] ?? 0), $this->sorted($blocks));
    }
}
