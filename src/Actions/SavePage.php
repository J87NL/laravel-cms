<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageTree;

class SavePage
{
    public function __construct(
        private readonly PageTree $tree,
        private readonly RecordRevision $recordRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Page $page, array $attributes, ?int $userId): Page
    {
        $save = fn (): Page => DB::transaction(function () use ($page, $attributes, $userId): Page {
            $this->apply($page, $attributes);

            $this->recordRevision->handle($page, $userId);

            return $page;
        });

        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            throw self::pathTaken($page);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function apply(Page $page, array $attributes): void
    {
        $page->fill(Arr::only($attributes, Page::CONTENT_ATTRIBUTES));

        $page->slug = $this->slug($page);

        $creating = ! $page->exists;

        if ($creating) {
            $page->sort_order = $this->tree->nextSortOrder($page->parent_id);
        }

        $pathChanges = $page->isDirty([
            'slug',
            'parent_id',
        ]);

        if ($creating || $pathChanges) {
            $this->tree->assertValidPlacement($page);
        }

        $page->save();

        if (! $creating && $pathChanges) {
            $this->tree->refreshDescendantPaths($page);
        }
    }

    public static function pathTaken(Page $page): ValidationException
    {
        return ValidationException::withMessages([
            'slug' => __('cms::validation.path_taken', [
                'path' => sprintf('/%s', $page->computePath()),
            ]),
        ]);
    }

    private function slug(Page $page): string
    {
        $slug = Str::slug((string) $page->slug);

        if ($slug !== '') {
            return $slug;
        }

        $slug = Str::slug((string) $page->title);

        return $slug === '' ? 'page' : $slug;
    }
}
