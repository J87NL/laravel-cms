<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Html\PlainText;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;
use J87NL\Cms\Pages\WordDiff;

/**
 * @phpstan-type FieldDiff array{field: string, before: mixed, after: mixed}
 * @phpstan-type ValueDiff array{key: string, before: mixed, after: mixed, words: list<array{type: string, text: string}>|null}
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
    public function handle(Page $page): ?array
    {
        $revision = $this->pending->find($page);

        if (is_null($revision)) {
            return null;
        }

        $plan = $this->applier->plan($page, $revision);
        $result = $plan->page($page);
        $before = new Collection(array_values($plan->live->all()));
        $after = $result->blocks;
        $moved = $this->movedIds($before, $after);
        $blocks = $this->blocks($before, $after, $moved);
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
    private function blocks(Collection $before, Collection $after, array $moved): array
    {
        $beforeById = $before->keyBy('id');
        $afterById = $after->keyBy('id');
        $removedAfter = [];
        $anchor = 'start';

        foreach ($before->values() as $position => $block) {
            if ($afterById->has($block->id)) {
                $anchor = $block->id;

                continue;
            }

            $removedAfter[$anchor][] = $this->entry($block, null, $position + 1, null, $moved);
        }

        $entries = $removedAfter['start'] ?? [];

        foreach ($after->values() as $position => $block) {
            $previous = $beforeById->get($block->id);
            $previousPosition = is_null($previous) ? null : $before->search(fn (Block $candidate): bool => $candidate->id === $block->id);

            $entries[] = $this->entry($previous, $block, is_int($previousPosition) ? $previousPosition + 1 : null, $position + 1, $moved);

            foreach ($removedAfter[$block->id] ?? [] as $removed) {
                $entries[] = $removed;
            }
        }

        return $entries;
    }

    /**
     * @param  list<int>  $moved
     * @return BlockDiff
     */
    private function entry(?Block $before, ?Block $after, ?int $positionBefore, ?int $positionAfter, array $moved): array
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
                $status === self::CHANGED,
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

            $html = (is_string($old) && PlainText::looksLikeHtml($old)) || (is_string($new) && PlainText::looksLikeHtml($new));
            $old = $html && is_string($old) ? PlainText::fromHtml($old) : $old;
            $new = $html && is_string($new) ? PlainText::fromHtml($new) : $new;

            $changes[] = [
                'key' => (string) $key,
                'before' => $old,
                'after' => $new,
                'words' => $withWords && is_string($old) && is_string($new) ? WordDiff::words($old, $new) : null,
            ];
        }

        return $changes;
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
