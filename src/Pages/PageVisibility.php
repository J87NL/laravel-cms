<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;

class PageVisibility
{
    public function isPublic(Page $page): bool
    {
        if (! $page->isPublished()) {
            return false;
        }

        return $this->hiddenAncestorPaths([$page->path]) === [];
    }

    public function hasPublicAncestry(?int $parentId): bool
    {
        $visited = [];

        while (! is_null($parentId) && ! in_array($parentId, $visited, true)) {
            $visited[] = $parentId;

            $parent = Page::query()
                ->select([
                    'id',
                    'parent_id',
                    'status',
                ])
                ->find($parentId);

            if (is_null($parent) || ! $parent->isPublished()) {
                return false;
            }

            $parentId = $parent->parent_id;
        }

        return true;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function hiddenAncestorPaths(array $paths): array
    {
        $ancestors = [];

        foreach ($paths as $path) {
            foreach ($this->ancestorPaths($path) as $ancestor) {
                $ancestors[$ancestor] = true;
            }
        }

        if ($ancestors === []) {
            return [];
        }

        return array_values(Page::query()
            ->whereIn('path', array_keys($ancestors))
            ->where('status', '!=', PageStatus::Published->value)
            ->pluck('path')
            ->map(fn (mixed $path): string => (string) $path)
            ->all());
    }

    /**
     * @param  list<string>  $hiddenAncestors
     */
    public function isHiddenBy(string $path, array $hiddenAncestors): bool
    {
        return array_intersect($this->ancestorPaths($path), $hiddenAncestors) !== [];
    }

    /**
     * @return list<string>
     */
    private function ancestorPaths(string $path): array
    {
        $segments = explode('/', $path);
        $ancestors = [];

        for ($length = 1; $length < count($segments); $length++) {
            $ancestors[] = implode('/', array_slice($segments, 0, $length));
        }

        return $ancestors;
    }
}
