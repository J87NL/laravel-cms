<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;

class SaveMenuItem
{
    public function handle(Menu $menu, MenuItem $item, ?int $pageId, ?string $url, ?string $label, ?int $parentId): MenuItem
    {
        return DB::transaction(function () use ($menu, $item, $pageId, $url, $label, $parentId): MenuItem {
            $this->assertUsableParent($menu, $item, $parentId);

            if (! $item->exists) {
                $item->menu()->associate($menu);
                $item->sort_order = $this->nextSortOrder($menu);
            }

            $item->fill([
                'page_id' => $pageId,
                'url' => is_null($pageId) ? $url : null,
                'label' => $label === '' ? null : $label,
                'parent_id' => $parentId,
            ]);

            if (is_null($item->page_id) && (is_null($item->url) || $item->url === '')) {
                throw ValidationException::withMessages([
                    'url' => __('cms::validation.menu_link_required'),
                ]);
            }

            if (is_null($item->page_id) && is_null($item->label)) {
                throw ValidationException::withMessages([
                    'label' => __('cms::validation.menu_label_required'),
                ]);
            }

            $item->save();

            return $item;
        });
    }

    private function assertUsableParent(Menu $menu, MenuItem $item, ?int $parentId): void
    {
        if (is_null($parentId)) {
            return;
        }

        $parentIsTopLevel = MenuItem::query()
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->whereKey($parentId)
            ->when($item->exists, fn ($query) => $query->whereKeyNot($item->id))
            ->exists();

        if (! $parentIsTopLevel) {
            throw ValidationException::withMessages([
                'parent_id' => __('cms::validation.menu_parent_invalid'),
            ]);
        }

        if (! $item->exists) {
            return;
        }

        $hasChildren = MenuItem::query()
            ->where('parent_id', $item->id)
            ->exists();

        if ($hasChildren) {
            throw ValidationException::withMessages([
                'parent_id' => __('cms::validation.menu_item_has_children'),
            ]);
        }
    }

    private function nextSortOrder(Menu $menu): int
    {
        $max = MenuItem::query()
            ->where('menu_id', $menu->id)
            ->max('sort_order');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }
}
