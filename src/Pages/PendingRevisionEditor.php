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
        $revision = $this->find($page);

        if (is_null($revision)) {
            return $this->recordRevision->snapshot($page);
        }

        return self::content($this->withLiveBlocks($page, $revision->snapshot));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function withLiveBlocks(Page $page, array $snapshot): array
    {
        $base = self::base($snapshot);

        if (is_null($base)) {
            return $snapshot;
        }

        $content = self::content($snapshot);
        $knownIds = array_map(fn (array $block): int => (int) ($block['id'] ?? 0), [
            ...$base['blocks'],
            ...$content['blocks'],
        ]);
        $added = array_values(array_filter(
            $this->recordRevision->snapshot($page)['blocks'],
            fn (array $block): bool => ! in_array((int) $block['id'], $knownIds, true),
        ));

        if ($added === []) {
            return $snapshot;
        }

        $snapshot['blocks'] = [
            ...$content['blocks'],
            ...$added,
        ];
        $snapshot['pending']['base']['blocks'] = [
            ...$base['blocks'],
            ...$added,
        ];

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{page: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    public static function content(array $snapshot): array
    {
        return [
            'page' => is_array($snapshot['page'] ?? null) ? $snapshot['page'] : [],
            'blocks' => array_values(is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{page: array<string, mixed>, blocks: list<array<string, mixed>>}|null
     */
    public static function base(array $snapshot): ?array
    {
        $base = $snapshot['pending']['base'] ?? null;

        return is_array($base) ? self::content($base) : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function editedBy(array $snapshot): ?int
    {
        $editedBy = $snapshot['pending']['edited_by'] ?? null;

        return is_numeric($editedBy) ? (int) $editedBy : null;
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
    public function updatePage(Page $page, array $attributes, ?int $userId): ?Revision
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
     * @return array{Revision|null, array<string, mixed>}
     */
    public function addBlock(Page $page, BlockType $type, array $data, ?int $userId): array
    {
        $added = [];

        $revision = $this->change($page, $userId, function (array $snapshot) use ($type, $data, &$added): array {
            $orders = array_map(fn (array $block): int => (int) ($block['sort_order'] ?? 0), $snapshot['blocks']);

            $added = [
                'id' => $this->nextNewId($snapshot),
                'type' => $type->key(),
                'data' => $type->prepare($data),
                'sort_order' => max([0, ...$orders]) + 1,
                'is_visible' => true,
            ];

            $snapshot['blocks'][] = $added;
            $snapshot['pending']['next_new_id'] = $added['id'] - 1;

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
    public function updateBlock(Page $page, int $blockId, BlockType $type, array $data, ?int $userId): ?Revision
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

    public function removeBlock(Page $page, int $blockId, ?int $userId): ?Revision
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
    public function reorderBlocks(Page $page, array $blockIds, ?int $userId): ?Revision
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
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function change(Page $page, ?int $userId, callable $mutate): ?Revision
    {
        $revision = $this->find($page);

        if (is_null($revision)) {
            $live = $this->recordRevision->snapshot($page);

            $revision = new Revision([
                'snapshot' => [
                    ...$live,
                    'pending' => [
                        'base' => $live,
                    ],
                ],
                'is_pending' => true,
                'created_by' => $userId,
            ]);

            $revision->page()->associate($page);
        }

        $snapshot = $mutate($revision->exists ? $this->withLiveBlocks($page, $revision->snapshot) : $revision->snapshot);
        $snapshot['pending']['edited_by'] = $userId;

        if (! self::changes($snapshot)) {
            if ($revision->exists) {
                $revision->delete();
            }

            return null;
        }

        $revision->snapshot = $snapshot;
        $revision->save();

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function changes(array $snapshot): bool
    {
        $base = self::base($snapshot);

        if (is_null($base)) {
            return true;
        }

        $content = self::content($snapshot);

        return self::comparable($content) !== self::comparable($base);
    }

    /**
     * @param  array{page: array<string, mixed>, blocks: list<array<string, mixed>>}  $content
     * @return array<string, mixed>
     */
    private static function comparable(array $content): array
    {
        $blocks = $content['blocks'];

        usort($blocks, fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        return [
            'page' => self::normalizedPage($content['page']),
            'blocks' => array_map(fn (array $block): string => sprintf('%d:%s', (int) ($block['id'] ?? 0), self::blockKey($block)), $blocks),
        ];
    }

    public static function sameValue(string $field, mixed $first, mixed $second): bool
    {
        return self::normalizedPage([$field => $first]) === self::normalizedPage([$field => $second]);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public static function blockKey(array $block): string
    {
        return (string) json_encode([
            $block['type'] ?? null,
            self::sortedKeys($block['data'] ?? null),
            (bool) ($block['is_visible'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function normalizedPage(array $attributes): array
    {
        $page = new Page;
        $page->forceFill(Arr::only($attributes, Page::CONTENT_ATTRIBUTES));

        return Arr::only($page->attributesToArray(), Page::CONTENT_ATTRIBUTES);
    }

    private static function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => self::sortedKeys($item), $value);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function nextNewId(array $snapshot): int
    {
        $next = $snapshot['pending']['next_new_id'] ?? null;

        if (is_numeric($next)) {
            return (int) $next;
        }

        $ids = array_map(fn (array $block): int => (int) ($block['id'] ?? 0), self::content($snapshot)['blocks']);

        return min([0, ...$ids]) - 1;
    }
}
