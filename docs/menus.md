# Menus

A site has named menus, `main` and `footer` by default (`cms.menus`). A menu is a `J87NL\Cms\Models\Menu` with a
`key`; its items are `J87NL\Cms\Models\MenuItem` records.

## Menu items

An item links either to a page or to a URL:

| Field | Purpose |
|---|---|
| `page_id` | The linked page. The link follows the page when its path changes |
| `url` | A URL, used when there is no page |
| `label` | The text. Required for a URL; for a page it defaults to the page's menu title or title |
| `parent_id` | A top-level item of the same menu, or `null` |
| `sort_order` | The position within the menu |

Menus have two levels: an item can only be placed under a top-level item, and an item that has items below it cannot
be placed under another item.

## Saving items

```php
use J87NL\Cms\Actions\SaveMenuItem;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;

$menu = Menu::forKey('main');

app(SaveMenuItem::class)->handle($menu, new MenuItem, pageId: $page->id, url: null, label: null, parentId: null);

app(SaveMenuItem::class)->handle($menu, new MenuItem, pageId: null, url: 'https://github.com/J87NL', label: 'GitHub', parentId: null);
```

`SaveMenuItem::handle(Menu $menu, MenuItem $item, ?int $pageId, ?string $url, ?string $label, ?int $parentId): MenuItem`
creates or updates an item. A new item goes to the end of the menu. When a page is given, the URL is cleared. It
throws a `ValidationException` when neither a page nor a URL is given, when a URL item has no label, or when the
parent is not usable.

`SaveMenuItem` does not check the URL itself. Validate it with the `J87NL\Cms\Rules\SafeLink` rule, as the MCP tool
does. `Menu::forKey(string $key)` returns the menu with that key and creates it when it does not exist yet.

Deleting a page deletes the menu items that link to it.

## Visibility

An item that links to a page only shows while that page and all its ancestors are published. An item with a URL
always shows, unless it is placed under a top-level item that is hidden: the items below a hidden item are hidden with
it. You can link an item to a draft page beforehand; it appears once the page is published.

## Rendering

```blade
<x-cms::menu key="main" />
```

The component renders a `nav` with nested `ul` lists, and marks the link of the current path with
`aria-current="page"`. Nothing is rendered for a menu without visible items, and no menus are rendered on the
not-found page. See [Blade components](blade-components.md#menu).

The `aria-label` of the `nav` is the translation `cms::public.menus.{key}`. When you add a menu key, add that
translation in `lang/vendor/cms/{locale}/public.php`:

```php
<?php

declare(strict_types=1);

return [
    'menus' => [
        'main' => 'Main menu',
        'footer' => 'Footer menu',
        'sidebar' => 'Sidebar menu',
    ],
];
```

The admin label of a menu, `Menu::label()`, comes from `cms::menus.keys.{key}` and falls back to the key.

`J87NL\Cms\Rendering\MenuBuilder::tree(string $key, string $currentPath)` returns the visible items as a nested array
of `label`, `href`, `current` and `children`, if you want to render a menu yourself.
