<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Html\PlainText;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;
use J87NL\Cms\Pages\WordDiff;

/**
 * @phpstan-type FieldDiff array{field: string, before: mixed, after: mixed}
 * @phpstan-type ValueDiff array{key: string, before: mixed, after: mixed, words: list<array{type: string, text: string}>|null, markup_changed: bool, links: array{before: list<string>, after: list<string>}|null}
 * @phpstan-type BlockDiff array{id: int, type: string, label: string, status: string, moved: bool, position: array{before: int|null, after: int|null}, visible: array{before: bool|null, after: bool|null}, changes: list<ValueDiff>}
 * @phpstan-type RevisionDiff array{revision_id: int, fields: list<FieldDiff>, blocks: list<BlockDiff>, summary: array{fields: int, added: int, removed: int, changed: int, moved: int, reordered: bool}}
 */
class DiffPendingRevision
{
    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const string CHANGED = 'changed';

    public const string UNCHANGED = 'unchanged';

    public function __construct(
        private readonly PendingRevisionEditor $pending,
        private readonly PendingRevisionApplier $applier,
        private readonly BlockRegistry $registry,
    ) {}

    /**
     * @return RevisionDiff|null
     */
    public function handle(Page $page, ?Revision $revision = null, bool $withWords = true): ?array
    {
        $revision ??= $this->pending->find($page);

        if (is_null($revision)) {
            return null;
        }

        $plan = $this->applier->plan($page, $revision);
        $result = $plan->page($page);
        $before = new Collection(array_values($plan->live->all()));
        $after = $result->blocks;
        $moved = $this->movedIds($before, $after);
        $blocks = $this->blocks($before, $after, $moved, $withWords);
        $fields = $this->fields($page, $result);

        return [
            'revision_id' => $revision->id,
            'fields' => $fields,
            'blocks' => $blocks,
            'summary' => [
                'fields' => count($fields),
                'added' => $this->count($blocks, self::ADDED),
                'removed' => $this->count($blocks, self::REMOVED),
                'changed' => $this->count($blocks, self::CHANGED),
                'moved' => count($moved),
                'reordered' => $moved !== [],
            ],
        ];
    }

    /**
     * @return list<FieldDiff>
     */
    private function fields(Page $live, Page $result): array
    {
        $fields = [];

        foreach (Page::CONTENT_ATTRIBUTES as $field) {
            $before = $live->getAttribute($field);
            $after = $result->getAttribute($field);

            if (PendingRevisionEditor::sameValue($field, $before, $after)) {
                continue;
            }

            $fields[] = [
                'field' => $field,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $fields;
    }

    /**
     * @param  Collection<int, Block>  $before
     * @param  Collection<int, Block>  $after
     * @param  list<int>  $moved
     * @return list<BlockDiff>
     */
    private function blocks(Collection $before, Collection $after, array $moved, bool $withWords): array
    {
        $beforeById = $before->keyBy('id');
        $removedBefore = [];
        $removedAfter = [];

        foreach ($this->removedAnchors($before, $after, $moved) as $position => [$side, $anchor]) {
            $removed = $this->entry($before->values()->get($position), null, $position + 1, null, $moved, $withWords);

            if ($side === 'before') {
                $removedBefore[$anchor][] = $removed;

                continue;
            }

            $removedAfter[$anchor][] = $removed;
        }

        $entries = $removedAfter['start'] ?? [];

        foreach ($after->values() as $position => $block) {
            $previous = $beforeById->get($block->id);
            $previousPosition = is_null($previous) ? null : $before->search(fn (Block $candidate): bool => $candidate->id === $block->id);

            $entries = [
                ...$entries,
                ...$removedBefore[$block->id] ?? [],
                $this->entry($previous, $block, is_int($previousPosition) ? $previousPosition + 1 : null, $position + 1, $moved, $withWords),
                ...$removedAfter[$block->id] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * @param  Collection<int, Block>  $before
     * @param  Collection<int, Block>  $after
     * @param  list<int>  $moved
     * @return array<int, array{string, int|string}>
     */
    private function removedAnchors(Collection $before, Collection $after, array $moved): array
    {
        $afterIds = array_map('intval', $after->modelKeys());
        $live = array_map('intval', $before->values()->modelKeys());
        $stable = array_values(array_filter($live, fn (int $id): bool => in_array($id, $afterIds, true) && ! in_array($id, $moved, true)));
        $anchors = [];

        foreach ($live as $position => $id) {
            if (in_array($id, $afterIds, true)) {
                continue;
            }

            $previous = array_values(array_filter(array_slice($live, 0, $position), fn (int $candidate): bool => in_array($candidate, $stable, true)));
            $next = array_values(array_filter(array_slice($live, $position + 1), fn (int $candidate): bool => in_array($candidate, $stable, true)));

            $anchors[$position] = match (true) {
                $previous !== [] => ['after', $previous[array_key_last($previous)]],
                $next !== [] => ['before', $next[0]],
                default => ['after', 'start'],
            };
        }

        return $anchors;
    }

    /**
     * @param  list<int>  $moved
     * @return BlockDiff
     */
    private function entry(?Block $before, ?Block $after, ?int $positionBefore, ?int $positionAfter, array $moved, bool $withWords): array
    {
        $block = $after ?? $before;
        $status = $this->status($before, $after);
        $type = $this->registry->get((string) $block?->type);

        return [
            'id' => (int) $block?->id,
            'type' => (string) $block?->type,
            'label' => is_null($type) ? (string) $block?->type : $type->label(),
            'status' => $status,
            'moved' => in_array((int) $block?->id, $moved, true),
            'position' => [
                'before' => $positionBefore,
                'after' => $positionAfter,
            ],
            'visible' => [
                'before' => $before?->is_visible,
                'after' => $after?->is_visible,
            ],
            'changes' => $status === self::UNCHANGED ? [] : $this->values(
                is_array($before?->data) ? $before->data : [],
                is_array($after?->data) ? $after->data : [],
                $withWords && $status === self::CHANGED,
            ),
        ];
    }

    private function status(?Block $before, ?Block $after): string
    {
        if (is_null($before)) {
            return self::ADDED;
        }

        if (is_null($after)) {
            return self::REMOVED;
        }

        if ($this->blockKey($before) === $this->blockKey($after)) {
            return self::UNCHANGED;
        }

        return self::CHANGED;
    }

    private function blockKey(Block $block): string
    {
        return PendingRevisionEditor::blockKey([
            'type' => $block->type,
            'data' => $block->data,
            'is_visible' => $block->is_visible,
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<ValueDiff>
     */
    private function values(array $before, array $after, bool $withWords): array
    {
        $beforeLeaves = $this->leaves($before);
        $afterLeaves = $this->leaves($after);
        $changes = [];

        foreach (array_keys([...$beforeLeaves, ...$afterLeaves]) as $key) {
            $old = $beforeLeaves[$key] ?? null;
            $new = $afterLeaves[$key] ?? null;

            if ($old === $new) {
                continue;
            }

            $changes[] = $this->change((string) $key, $old, $new, $withWords);
        }

        return $changes;
    }

    /**
     * @return ValueDiff
     */
    private function change(string $key, mixed $old, mixed $new, bool $withWords): array
    {
        $html = (is_string($old) && PlainText::looksLikeHtml($old)) || (is_string($new) && PlainText::looksLikeHtml($new));

        if (! $html) {
            return [
                'key' => $key,
                'before' => $old,
                'after' => $new,
                'words' => $withWords && is_string($old) && is_string($new) ? WordDiff::words($old, $new) : null,
                'markup_changed' => false,
                'links' => null,
            ];
        }

        $oldText = is_string($old) ? PlainText::fromHtml($old) : $old;
        $newText = is_string($new) ? PlainText::fromHtml($new) : $new;
        $oldLinks = is_string($old) ? PlainText::links($old) : [];
        $newLinks = is_string($new) ? PlainText::links($new) : [];

        return [
            'key' => $key,
            'before' => $oldText,
            'after' => $newText,
            'words' => $withWords && is_string($oldText) && is_string($newText) ? WordDiff::words($oldText, $newText) : null,
            'markup_changed' => $oldText === $newText,
            'links' => $oldLinks === $newLinks ? null : [
                'before' => $oldLinks,
                'after' => $newLinks,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function leaves(array $data, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : sprintf('%s.%s', $prefix, $key);

            if (is_array($value) && $value !== []) {
                $leaves = [
                    ...$leaves,
                    ...$this->leaves($value, $path),
                ];

                continue;
            }

            $leaves[$path] = is_array($value) ? null : $value;
        }

        return $leaves;
    }

    /**
     * @param  Collection<int, Block>  $before
     * @param  Collection<int, Block>  $after
     * @return list<int>
     */
    private function movedIds(Collection $before, Collection $after): array
    {
        $afterIds = array_map('intval', $after->modelKeys());
        $beforeIds = array_map('intval', $before->modelKeys());
        $common = array_values(array_intersect($beforeIds, $afterIds));
        $commonAfter = array_values(array_intersect($afterIds, $beforeIds));
        $moved = [];

        foreach (WordDiff::sequence(array_map('strval', $common), array_map('strval', $commonAfter)) as [$type, $id]) {
            if ($type === WordDiff::EQUAL) {
                continue;
            }

            $moved[] = (int) $id;
        }

        return array_values(array_unique($moved));
    }

    /**
     * @param  list<BlockDiff>  $blocks
     */
    private function count(array $blocks, string $status): int
    {
        return count(array_filter($blocks, fn (array $block): bool => $block['status'] === $status));
    }
}
