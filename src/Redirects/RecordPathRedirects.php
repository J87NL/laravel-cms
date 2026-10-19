<?php

declare(strict_types=1);

namespace J87NL\Cms\Redirects;

use J87NL\Cms\Actions\SaveRedirect;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageVisibility;

class RecordPathRedirects
{
    /**
     * @var array<string, bool>
     */
    private array $wasPublic = [];

    public function __construct(
        private readonly SaveRedirect $saveRedirect,
        private readonly PageVisibility $visibility,
    ) {}

    public function updating(Page $page): void
    {
        if (! $page->isDirty('path')) {
            return;
        }

        $originalPath = (string) $page->getOriginal('path');

        if (array_key_exists($originalPath, $this->wasPublic)) {
            return;
        }

        $this->wasPublic = [
            ...$this->wasPublic,
            ...$this->subtreeVisibility($page, $originalPath),
        ];
    }

    public function updated(Page $page): void
    {
        if (! $page->wasChanged('path')) {
            return;
        }

        $oldPath = (string) $page->getOriginal('path');
        $wasPublic = $this->wasPublic[$oldPath] ?? false;

        unset($this->wasPublic[$oldPath]);

        if (! $this->isPublic($page)) {
            return;
        }

        if (! $wasPublic) {
            $this->saveRedirect->releasePath($page->path);

            return;
        }

        $this->saveRedirect->forPathChange($oldPath, $page->path);
    }

    private function isPublic(Page $page): bool
    {
        return $page->isPublished() && $this->visibility->hasPublicAncestry($page->parent_id);
    }

    /**
     * @return array<string, bool>
     */
    private function subtreeVisibility(Page $page, string $originalPath): array
    {
        $parentId = $page->getOriginal('parent_id');

        $visibility = [
            $originalPath => $page->getOriginal('status') === PageStatus::Published
                && $this->visibility->hasPublicAncestry(is_null($parentId) ? null : (int) $parentId),
        ];

        $descendants = Page::query()
            ->select([
                'id',
                'path',
                'status',
            ])
            ->where('path', 'like', sprintf('%s/%%', $originalPath))
            ->get()
            ->filter(fn (Page $descendant): bool => str_starts_with($descendant->path, sprintf('%s/', $originalPath)))
            ->sortBy(fn (Page $descendant): int => substr_count($descendant->path, '/'));

        foreach ($descendants as $descendant) {
            $parentPath = substr($descendant->path, 0, (int) strrpos($descendant->path, '/'));

            $visibility[$descendant->path] = ($visibility[$parentPath] ?? false) && $descendant->isPublished();
        }

        return $visibility;
    }
}
