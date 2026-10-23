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
        private readonly PendingRevisionEditor $pendingRevisionEditor,
    ) {}

    public function apply(Page $page, Revision $revision, bool $withPath = true): void
    {
        $plan = $this->plan($page, $revision, $withPath);

        $this->applyPage($page, $plan->pageChanges, $withPath);
        $this->applyBlocks($page, $plan);

        $revision->delete();
    }

    public function plan(Page $page, Revision $revision, bool $withPath = true): PendingRevisionPlan
    {
        $snapshot = $this->pendingRevisionEditor->withLiveBlocks($page, $revision->snapshot);
        $content = PendingRevisionEditor::content($snapshot);
        $base = PendingRevisionEditor::base($snapshot) ?? $this->recordRevision->snapshot($page);
        $pendingPage = $withPath ? $content['page'] : Arr::except($content['page'], [
            'slug',
            'parent_id',
        ]);

        $live = Block::query()
            ->where('page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        return $this->planBlocks(
            $live,
            $this->pageChanges($page, $pendingPage, $base['page'], $withPath),
            $content['blocks'],
            $base['blocks'],
        );
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function pageChanges(Page $page, array $pending, array $base, bool $withPath): array
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

        if ($changes === [] || ! $withPath) {
            return $changes;
        }

        return $this->restoreRevision->pageAttributes($page, $changes);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function applyPage(Page $page, array $changes, bool $withPath): void
    {
        if ($changes === []) {
            return;
        }

        if (! $withPath) {
            $page->fill($changes);
            $page->save();

            return;
        }

        try {
            $this->savePage->apply($page, $changes);
        } catch (UniqueConstraintViolationException) {
            throw SavePage::pathTaken($page);
        }
    }

    /**
     * @param  Collection<int, Block>  $live
     * @param  array<string, mixed>  $pageChanges
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $base
     */
    private function planBlocks(Collection $live, array $pageChanges, array $pending, array $base): PendingRevisionPlan
    {
        $baseById = $this->byId($base);
        $pendingById = $this->byId($pending);
        $deletedIds = [];

        foreach (array_keys(array_diff_key($baseById, $pendingById)) as $id) {
            if ($live->has($id)) {
                $deletedIds[] = $id;
            }
        }

        $remaining = $live->filter(fn (Block $block, int $id): bool => ! in_array($id, $deletedIds, true));
        $pendingIds = [];
        $createdIds = [];
        $writes = [];

        foreach ($this->sorted($pending) as $block) {
            $id = (int) ($block['id'] ?? 0);
            $baseBlock = $baseById[$id] ?? null;
            $exists = $remaining->has($id);

            if (! is_null($baseBlock) && ! $this->differs($block, $baseBlock)) {
                if ($exists) {
                    $pendingIds[] = $id;
                }

                continue;
            }

            $writes[] = [
                'id' => $id,
                'type' => (string) ($block['type'] ?? ''),
                'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
                'is_visible' => (bool) ($block['is_visible'] ?? true),
                'sort_order' => (int) ($block['sort_order'] ?? 0),
            ];
            $pendingIds[] = $id;

            if (! $exists) {
                $createdIds[] = $id;
            }
        }

        return new PendingRevisionPlan($pageChanges, $live, $deletedIds, $writes, $this->order($remaining, $pending, $base, $pendingIds, $createdIds));
    }

    /**
     * @param  Collection<int, Block>  $remaining
     * @param  list<array<string, mixed>>  $pending
     * @param  list<array<string, mixed>>  $base
     * @param  list<int>  $pendingIds
     * @param  list<int>  $createdIds
     * @return list<int>|null
     */
    private function order(Collection $remaining, array $pending, array $base, array $pendingIds, array $createdIds): ?array
    {
        if ($this->sharedOrder($pending, $base) !== $this->sharedOrder($base, $pending)) {
            return [
                ...$pendingIds,
                ...array_values(array_diff(array_map('intval', $remaining->keys()->all()), $pendingIds)),
            ];
        }

        if ($createdIds === []) {
            return null;
        }

        return $this->place(array_map('intval', $remaining->keys()->all()), $pendingIds, $createdIds);
    }

    private function applyBlocks(Page $page, PendingRevisionPlan $plan): void
    {
        $live = $plan->live;

        foreach ($plan->deletedIds as $id) {
            $live->get($id)?->delete();
            $live->forget($id);
        }

        $savedIds = [];

        foreach ($plan->writes as $write) {
            $saved = $live->get($write['id']) ?? new Block;

            $saved->fill([
                'type' => $write['type'],
                'data' => $write['data'],
                'is_visible' => $write['is_visible'],
            ]);

            if (! $saved->exists) {
                $saved->page()->associate($page);
                $saved->sort_order = $write['sort_order'];
            }

            $saved->save();

            $live->put($saved->id, $saved);
            $savedIds[$write['id']] = $saved->id;
        }

        if (is_null($plan->order)) {
            return;
        }

        $this->reorder($live, array_map(fn (int $id): int => $savedIds[$id] ?? $id, $plan->order));
    }

    /**
     * @param  list<int>  $liveIds
     * @param  list<int>  $pendingIds
     * @param  list<int>  $createdIds
     * @return list<int>
     */
    private function place(array $liveIds, array $pendingIds, array $createdIds): array
    {
        $order = $liveIds;

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

        return array_values($order);
    }

    /**
     * @param  Collection<int, Block>  $live
     * @param  list<int>  $order
     */
    private function reorder(Collection $live, array $order): void
    {
        $others = array_values(array_diff(array_map('intval', $live->keys()->all()), $order));

        foreach ([...$order, ...$others] as $position => $id) {
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
