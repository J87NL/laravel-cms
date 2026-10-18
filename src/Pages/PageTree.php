<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Page;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PageTree
{
    public const int MAX_PATH_LENGTH = 255;

    public function __construct(
        private readonly Router $router,
        private readonly PageVisibility $visibility,
    ) {}

    public function assertValidPlacement(Page $page): void
    {
        $this->assertValidParent($page);
        $this->assertHomeStaysPublic($page);

        $path = $page->computePath();

        $taken = Page::query()
            ->where('path', $path)
            ->when($page->exists, fn ($query) => $query->whereKeyNot($page->getKey()))
            ->exists();

        if ($taken) {
            throw $this->invalid('cms::validation.path_taken', $path);
        }

        foreach ($this->subtreePaths($page, $path) as $subtreePath) {
            $this->assertUsablePath($subtreePath);
        }
    }

    public function refreshDescendantPaths(Page $page): void
    {
        $children = Page::query()
            ->where('parent_id', $page->id)
            ->get();

        foreach ($children as $child) {
            $child->save();

            $this->refreshDescendantPaths($child);
        }
    }

    public function nextSortOrder(?int $parentId): int
    {
        $max = Page::query()
            ->where('parent_id', $parentId)
            ->max('sort_order');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    public function isReserved(string $path): bool
    {
        try {
            $route = $this->router
                ->getRoutes()
                ->match(Request::create(sprintf('/%s', $path)));
        } catch (HttpException) {
            return false;
        }

        return ! $route->isFallback;
    }

    /**
     * @return list<string>
     */
    private function subtreePaths(Page $page, string $path): array
    {
        $paths = [$path];

        if (! $page->exists) {
            return $paths;
        }

        $originalPath = (string) $page->getOriginal('path');

        if ($originalPath === $path) {
            return $paths;
        }

        $descendantPaths = Page::query()
            ->where('path', 'like', sprintf('%s/%%', $originalPath))
            ->pluck('path');

        foreach ($descendantPaths as $descendantPath) {
            $paths[] = sprintf('%s%s', $path, substr((string) $descendantPath, strlen($originalPath)));
        }

        return $paths;
    }

    private function assertUsablePath(string $path): void
    {
        if (strlen($path) > self::MAX_PATH_LENGTH) {
            throw $this->invalid('cms::validation.path_too_long', $path);
        }

        if ($this->isReserved($path)) {
            throw $this->invalid('cms::validation.path_reserved', $path);
        }
    }

    private function invalid(string $message, string $path): ValidationException
    {
        return ValidationException::withMessages([
            'slug' => __($message, [
                'path' => sprintf('/%s', $path),
                'max' => self::MAX_PATH_LENGTH,
            ]),
        ]);
    }

    private function assertHomeStaysPublic(Page $page): void
    {
        if (! $page->exists || ! $page->isDirty('parent_id') || is_null($page->parent_id)) {
            return;
        }

        if (! $page->is_home && ! $this->isAncestorOfHome($page)) {
            return;
        }

        $parent = Page::query()
            ->find($page->parent_id);

        if (! is_null($parent) && $this->visibility->isPublic($parent)) {
            return;
        }

        throw ValidationException::withMessages([
            'parent_id' => __('cms::validation.home_parent_not_public'),
        ]);
    }

    private function isAncestorOfHome(Page $page): bool
    {
        $homePath = Page::query()
            ->where('is_home', true)
            ->value('path');

        return is_string($homePath) && str_starts_with($homePath, sprintf('%s/', (string) $page->getOriginal('path')));
    }

    private function assertValidParent(Page $page): void
    {
        if (is_null($page->parent_id)) {
            return;
        }

        $parent = Page::query()
            ->find($page->parent_id);

        if (is_null($parent) || ($page->exists && $parent->is($page))) {
            throw ValidationException::withMessages([
                'parent_id' => __('cms::validation.invalid_parent'),
            ]);
        }

        if (! $page->exists) {
            return;
        }

        $originalPath = (string) $page->getOriginal('path');

        if (str_starts_with($parent->path, sprintf('%s/', $originalPath))) {
            throw ValidationException::withMessages([
                'parent_id' => __('cms::validation.invalid_parent'),
            ]);
        }
    }
}
