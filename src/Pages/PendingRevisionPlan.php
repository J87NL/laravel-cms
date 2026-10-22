<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;

final readonly class PendingRevisionPlan
{
    /**
     * @param  array<string, mixed>  $pageChanges
     * @param  Collection<int, Block>  $live
     * @param  list<int>  $deletedIds
     * @param  list<array{id: int, type: string, data: array<string, mixed>, is_visible: bool, sort_order: int}>  $writes
     * @param  list<int>|null  $order
     */
    public function __construct(
        public array $pageChanges,
        public Collection $live,
        public array $deletedIds,
        public array $writes,
        public ?array $order,
    ) {}

    public function page(Page $page): Page
    {
        $result = clone $page;
        $result->fill($this->pageChanges);

        if ($result->isDirty([
            'slug',
            'parent_id',
        ])) {
            $result->path = $result->computePath();
            $result->unsetRelation('parent');
        }

        $result->setRelation('blocks', $this->blocks($page));

        return $result;
    }

    /**
     * @return Collection<int, Block>
     */
    public function blocks(Page $page): Collection
    {
        $blocks = [];

        foreach ($this->live as $id => $block) {
            if (in_array($id, $this->deletedIds, true)) {
                continue;
            }

            $blocks[$id] = clone $block;
        }

        foreach ($this->writes as $write) {
            $block = $blocks[$write['id']] ?? $this->newBlock($page, $write);

            $block->fill([
                'type' => $write['type'],
                'data' => $write['data'],
                'is_visible' => $write['is_visible'],
            ]);

            $blocks[$write['id']] = $block;
        }

        $ordered = [];

        foreach ($this->order ?? array_keys($blocks) as $id) {
            if (! array_key_exists($id, $blocks)) {
                continue;
            }

            $ordered[] = $blocks[$id];
            unset($blocks[$id]);
        }

        $ordered = [
            ...$ordered,
            ...array_values($blocks),
        ];

        foreach ($ordered as $position => $block) {
            $block->sort_order = $position + 1;
        }

        return new Collection($ordered);
    }

    /**
     * @param  array{id: int, type: string, data: array<string, mixed>, is_visible: bool, sort_order: int}  $write
     */
    private function newBlock(Page $page, array $write): Block
    {
        $block = new Block;
        $block->forceFill([
            'id' => $write['id'],
            'page_id' => $page->id,
        ]);

        return $block;
    }
}
