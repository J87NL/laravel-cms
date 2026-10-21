<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;

class ReorderMenuItems
{
    public function move(Menu $menu, int $itemId, int $toItemId): void
    {
        if ($itemId === $toItemId) {
            return;
        }

        DB::transaction(function () use ($menu, $itemId, $toItemId): void {
            $items = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $ids = array_map('intval', $items->keys()->all());
            $from = array_search($itemId, $ids, true);
            $to = array_search($toItemId, $ids, true);

            if ($from === false || $to === false) {
                throw new InvalidArgumentException('Both menu items must belong to the menu that is reordered.');
            }

            array_splice($ids, $from, 1);
            array_splice($ids, $to, 0, [$itemId]);

            foreach ($ids as $position => $id) {
                $item = $items->get($id);
                $item->sort_order = $position + 1;

                if ($item->isDirty('sort_order')) {
                    $item->save();
                }
            }
        });
    }
}
