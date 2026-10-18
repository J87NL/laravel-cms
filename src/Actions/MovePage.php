<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageTree;

class MovePage
{
    public const string BEFORE = 'before';

    public const string AFTER = 'after';

    public const string INSIDE = 'inside';

    public function __construct(
        private readonly PageTree $tree,
        private readonly RecordRevision $recordRevision,
    ) {}

    public function handle(Page $page, Page $target, string $position, ?int $userId): void
    {
        if (! in_array($position, [self::BEFORE, self::AFTER, self::INSIDE], true)) {
            throw new InvalidArgumentException(sprintf('Unknown position [%s].', $position));
        }

        try {
            $this->move($page, $target, $position, $userId);
        } catch (UniqueConstraintViolationException) {
            throw SavePage::pathTaken($page);
        }
    }

    private function move(Page $page, Page $target, string $position, ?int $userId): void
    {
        DB::transaction(function () use ($page, $target, $position, $userId): void {
            $page->parent_id = $position === self::INSIDE ? $target->id : $target->parent_id;

            $parentChanged = $page->isDirty('parent_id');

            if ($parentChanged) {
                $this->tree->assertValidPlacement($page);
            }

            $siblings = array_values(Page::query()
                ->where('parent_id', $page->parent_id)
                ->whereKeyNot($page->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->all());

            $index = $this->insertIndex($siblings, $target, $position);

            array_splice($siblings, $index, 0, [$page]);

            foreach ($siblings as $offset => $sibling) {
                $sibling->sort_order = $offset + 1;

                if ($sibling->is($page)) {
                    continue;
                }

                $sibling->saveQuietly();
            }

            $page->save();

            if ($parentChanged) {
                $this->tree->refreshDescendantPaths($page);
            }

            $this->recordRevision->handle($page, $userId);
        });
    }

    /**
     * @param  list<Page>  $siblings
     */
    private function insertIndex(array $siblings, Page $target, string $position): int
    {
        if ($position === self::INSIDE) {
            return count($siblings);
        }

        foreach ($siblings as $index => $sibling) {
            if ($sibling->is($target)) {
                return $position === self::AFTER ? $index + 1 : $index;
            }
        }

        return count($siblings);
    }
}
