<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use InvalidArgumentException;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;

class DeleteMenuItem
{
    public function handle(Menu $menu, MenuItem $item): void
    {
        if ($item->menu_id !== $menu->id) {
            throw new InvalidArgumentException('The menu item does not belong to this menu.');
        }

        $item->delete();
    }
}
