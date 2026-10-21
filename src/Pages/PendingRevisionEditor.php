<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\RecordRevision;
use J87NL\Cms\Blocks\BlockType;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;

class PendingRevisionEditor
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
    ) {}

    public function find(Page $page): ?Revision
    {
        return Revision::query()
            ->pending()
            ->where('page_id', $page->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{page: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    public function snapshot(Page $page): array
    {
        return $this->find($page)?->snapshot ?? $this->recordRevision->snapshot($page);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function block(Page $page, int $blockId): ?array
    {
        foreach ($this->snapshot($page)['blocks'] as $block) {
            if ((int) ($block['id'] ?? 0) === $blockId) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updatePage(Page $page, array $attributes, ?int $userId): Revision
    {
        $changes = Arr::only($attributes, Page::CONTENT_ATTRIBUTES);

        if (array_key_exists('slug', $changes)) {
            $changes['slug'] = Str::slug((string) $changes['slug']);
        }

        return $this->change($page, $userId, function (array $snapshot) use ($changes): array {
            $snapshot['page'] = [
                ...$snapshot['page'],
                ...$changes,
            ];

            return $snapshot;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{Revision, array<string, mixed>}
     */
    public function addBlock(Page $page, BlockType $type, array $data, ?int $userId): array
    {
        $added = [];

        $revision = $this->change($page, $userId, function (array $snapshot) use ($type, $data, &$added): array {
            $ids = array_map(fn (array $block): int => (int) ($block['id'] ?? 0), $snapshot['blocks']);
            $orders = array_map(fn (array $block): int => (int) ($block['sort_order'] ?? 0), $snapshot['blocks']);

            $added = [
                'id' => min([0, ...$ids]) - 1,
                'type' => $type->key(),
                'data' => $type->prepare($data),
                'sort_order' => max([0, ...$orders]) + 1,
                'is_visible' => true,
            ];

            $snapshot['blocks'][] = $added;

            return $snapshot;
        });

        return [
            $revision,
            $added,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBlock(Page $page, int $blockId, BlockType $type, array $data, ?int $userId): Revision
    {
        return $this->change($page, $userId, function (array $snapshot) use ($blockId, $type, $data): array {
            foreach ($snapshot['blocks'] as $index => $block) {
                if ((int) ($block['id'] ?? 0) !== $blockId) {
                    continue;
                }

                $snapshot['blocks'][$index]['data'] = $type->prepare($data);
            }

            return $snapshot;
        });
    }

    public function removeBlock(Page $page, int $blockId, ?int $userId): Revision
    {
        return $this->change($page, $userId, function (array $snapshot) use ($blockId): array {
            $snapshot['blocks'] = array_values(array_filter(
                $snapshot['blocks'],
                fn (array $block): bool => (int) ($block['id'] ?? 0) !== $blockId,
            ));

            return $snapshot;
        });
    }

    /**
     * @param  list<int>  $blockIds
     */
    public function reorderBlocks(Page $page, array $blockIds, ?int $userId): Revision
    {
        return $this->change($page, $userId, function (array $snapshot) use ($blockIds): array {
            $current = array_map(fn (array $block): int => (int) ($block['id'] ?? 0), $snapshot['blocks']);
            $requested = array_values($blockIds);

            sort($current);
            sort($requested);

            if ($requested !== $current) {
                throw ValidationException::withMessages([
                    'block_ids' => __('cms::validation.block_order_incomplete'),
                ]);
            }

            $positions = array_flip(array_values($blockIds));
            $blocks = $snapshot['blocks'];

            usort($blocks, fn (array $a, array $b): int => $positions[(int) ($a['id'] ?? 0)] <=> $positions[(int) ($b['id'] ?? 0)]);

            foreach ($blocks as $position => $block) {
                $blocks[$position]['sort_order'] = $position + 1;
            }

            $snapshot['blocks'] = array_values($blocks);

            return $snapshot;
        });
    }

    public function discard(Page $page): bool
    {
        return Revision::query()
            ->pending()
            ->where('page_id', $page->id)
            ->delete() > 0;
    }

    /**
     * @param  callable(array{page: array<string, mixed>, blocks: list<array<string, mixed>>}): array{page: array<string, mixed>, blocks: list<array<string, mixed>>}  $mutate
     */
    private function change(Page $page, ?int $userId, callable $mutate): Revision
    {
        $revision = $this->find($page);

        if (is_null($revision)) {
            $revision = new Revision([
                'snapshot' => $this->recordRevision->snapshot($page),
                'is_pending' => true,
                'created_by' => $userId,
            ]);

            $revision->page()->associate($page);
        }

        $revision->snapshot = $mutate($revision->snapshot);
        $revision->save();

        return $revision;
    }
}
