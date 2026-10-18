<?php

declare(strict_types=1);

namespace J87NL\Cms\Rendering;

use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Pages\PageVisibility;

class MenuBuilder
{
    public function __construct(
        private readonly PageVisibility $visibility,
    ) {}

    /**
     * @return list<array{label: string, href: string, current: bool, children: list<array<string, mixed>>}>
     */
    public function tree(string $key, string $currentPath): array
    {
        $menu = Menu::query()
            ->where('key', $key)
            ->first();

        if (is_null($menu)) {
            return [];
        }

        $items = MenuItem::query()
            ->with('page')
            ->where('menu_id', $menu->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (MenuItem $item): bool => $item->isPublic());

        $hiddenAncestors = $this->visibility->hiddenAncestorPaths(array_values(array_filter(
            $items->map(fn (MenuItem $item): ?string => $item->page?->path)->all(),
            'is_string',
        )));

        $visible = $items->reject(fn (MenuItem $item): bool => ! is_null($item->page) && $this->visibility->isHiddenBy($item->page->path, $hiddenAncestors));

        $byParent = $visible->groupBy(fn (MenuItem $item): int => $item->parent_id ?? 0);

        return $this->branch($byParent->all(), 0, $this->normalize($currentPath));
    }

    /**
     * @param  array<int, iterable<MenuItem>>  $byParent
     * @return list<array{label: string, href: string, current: bool, children: list<array<string, mixed>>}>
     */
    private function branch(array $byParent, int $parentId, string $currentPath): array
    {
        $branch = [];

        foreach ($byParent[$parentId] ?? [] as $item) {
            $href = (string) $item->href();

            $branch[] = [
                'label' => $item->displayLabel(),
                'href' => $href,
                'current' => $this->normalize($href) === $currentPath,
                'children' => $this->branch($byParent, $item->id, $currentPath),
            ];
        }

        return $branch;
    }

    private function normalize(string $path): string
    {
        return sprintf('/%s', trim($path, '/'));
    }
}
