<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
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

    public function apply(Page $page, Revision $revision, bool $withPath = true): void
    {
        $content = PendingRevisionEditor::content($revision->snapshot);
        $base = PendingRevisionEditor::base($revision->snapshot) ?? $this->recordRevision->snapshot($page);
        $pendingPage = $withPath ? $content['page'] : Arr::except($content['page'], [
            'slug',
            'parent_id',
        ]);

        $this->applyPage($page, $pendingPage, $base['page'], $withPath);
        $this->applyBlocks($page, $content['blocks'], $base['blocks']);

        $revision->delete();
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $base
     */
    private function applyPage(Page $page, array $pending, array $base, bool $withPath): void
    {
        $changes = [];

        foreach (Page::CONTENT_ATTRIBUTES as $field) {
            if (! array_key_exists($field, $pending)) {
                continue;
            }

            if (PendingRevisionEditor::sameValue($field, $pending[$field], $base[$field] ?? null)) {
                continue;
            }

            $changes[$field] = $pending[$field];
        }

        if ($changes === []) {
            return;
        }

        if (! $withPath) {
            $page->fill($changes);
            $page->save();

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
        $createdIds = [];

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

            $created = ! $saved->exists;

            if ($created) {
                $saved->page()->associate($page);
                $saved->sort_order = (int) ($block['sort_order'] ?? 0);
            }

            $saved->save();

            $live->put($saved->id, $saved);
            $pendingIds[] = $saved->id;

            if ($created) {
                $createdIds[] = $saved->id;
            }
        }

        if ($this->sharedOrder($pending, $base) !== $this->sharedOrder($base, $pending)) {
            $this->reorder($live, $pendingIds);

            return;
        }

        if ($createdIds === []) {
            return;
        }

        $this->place($live, $pendingIds, $createdIds);
    }

    /**
     * @param  Collection<int, Block>  $live
     * @param  list<int>  $pendingIds
     * @param  list<int>  $createdIds
     */
    private function place(Collection $live, array $pendingIds, array $createdIds): void
    {
        $order = array_values(array_map('intval', $live
            ->reject(fn (Block $block): bool => in_array($block->id, $createdIds, true))
            ->sortBy([
                [
                    'sort_order',
                    'asc',
                ],
                [
                    'id',
                    'asc',
                ],
            ])
            ->keys()
            ->all()));

        foreach ($pendingIds as $index => $id) {
            if (! in_array($id, $createdIds, true)) {
                continue;
            }

            $previousId = $this->previousPlaced(array_slice($pendingIds, 0, $index), $order);

            if (is_null($previousId) || in_array($previousId, $createdIds, true)) {
                $after = is_null($previousId) ? false : array_search($previousId, $order, true);

                array_splice($order, $after === false ? 0 : $after + 1, 0, [$id]);

                continue;
            }

            $position = count($order);

            foreach (array_slice($pendingIds, $index + 1) as $nextId) {
                $found = array_search($nextId, $order, true);

                if ($found !== false) {
                    $position = $found;

                    break;
                }
            }

            array_splice($order, $position, 0, [$id]);
        }

        $this->reorder($live, $order);
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
     * @param  list<int>  $previousIds
     * @param  list<int>  $order
     */
    private function previousPlaced(array $previousIds, array $order): ?int
    {
        foreach (array_reverse($previousIds) as $previousId) {
            if (in_array($previousId, $order, true)) {
                return $previousId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $base
     */
    private function differs(array $pending, array $base): bool
    {
        return PendingRevisionEditor::blockKey($pending) !== PendingRevisionEditor::blockKey($base);
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
     * @param  list<array<string, mixed>>  $others
     * @return list<int>
     */
    private function sharedOrder(array $blocks, array $others): array
    {
        $otherIds = array_keys($this->byId($others));

        return array_values(array_filter(
            array_map(fn (array $block): int => (int) ($block['id'] ?? 0), $this->sorted($blocks)),
            fn (int $id): bool => in_array($id, $otherIds, true),
        ));
    }
}
