# Models

All models live in `J87NL\Cms\Models`. The tables are created by the package's migrations, which load
automatically.

| Model | Table | Holds |
|---|---|---|
| `Page` | `cms_pages` | Pages, see [Pages](pages.md) |
| `Block` | `cms_blocks` | Blocks of a page, see [Blocks](blocks.md) |
| `MediaAsset` | `cms_media_assets` | Images, with the file in the media library's `media` table, see [Media](media.md) |
| `Menu` | `cms_menus` | Menus by key, see [Menus](menus.md) |
| `MenuItem` | `cms_menu_items` | Menu items |
| `Revision` | `cms_revisions` | Page snapshots, see [Revisions](revisions.md) |
| `Redirect` | `cms_redirects` | Redirects, see [Redirects](redirects.md) |
| `SiteSettings` | `cms_settings` | The site settings row, see [Site settings](site-settings.md) |

Writing to these models directly skips the rules of the [actions](actions.md), such as path checks, sanitizing and
revisions. Saving or deleting a `Page`, `Block`, `Menu`, `MenuItem`, `SiteSettings`, `MediaAsset` or the media library
`Spatie\MediaLibrary\MediaCollections\Models\Media` item of a `MediaAsset` still flushes the [page cache](page-cache.md), and a path change of a `Page` still creates [redirects](redirects.md).

## Page

| Member | Purpose |
|---|---|
| `parent()`, `children()` | The tree. Children are ordered by `sort_order` |
| `blocks()` | The blocks, ordered by `sort_order` |
| `revisions()` | The revisions, newest first |
| `scopePublished()` | `Page::query()->published()`: pages with the status `published` (ancestors not checked) |
| `isPublished()` | Whether the status is `published` |
| `url()` | `/` for the home page, `/{path}` otherwise |
| `menuLabel()` | `menu_title`, or the title when empty |
| `computePath()` | The path from the parent's path and the slug |
| `isAncestorOf(Page $page)` | Whether the other page is one of this page's subpages |

The `path` is recomputed on every save. `status` is cast to `PageStatus`; `publish_at` and `unpublish_at` to dates.

## Block

| Member | Purpose |
|---|---|
| `page()` | The page |
| `blockType()` | The registered `BlockType` for `type`, or `null` when it is unknown or disabled |

`data` is cast to an array.

## MediaAsset

Implements the media library's `HasMedia`. See [Media](media.md#using-an-asset) for its methods. The static
`MediaAsset::widths()` returns the configured conversion widths, sorted, and `MediaAsset::conversionName(int $width)`
the conversion name, for example `w960`.

## Menu and MenuItem

| Member | Purpose |
|---|---|
| `Menu::forKey(string $key)` | The menu with that key, created when missing |
| `Menu::items()` | The items, ordered by `sort_order` |
| `Menu::label()` | The translated menu name, or the key |
| `MenuItem::href()` | The page URL, or `url` |
| `MenuItem::displayLabel()` | `label`, or the page's menu label, or the URL |
| `MenuItem::isPublic()` | Whether the item has a URL, or links to a published page (ancestors not checked) |

## Redirect

| Member | Purpose |
|---|---|
| `fromUrl()` | `/{from_path}` |
| `isExternal()` | Whether `to` is not a path on this site |
| `recordHit()` | Increment `hits` and set `last_hit_at` |

`Redirect::STATUS_CODES` is `[301, 302]`. `source` is cast to `RedirectSource`.

## Revision

`snapshot` is cast to an array with the keys `page` and `blocks`. `page()` returns the page, `author()` the user in
`created_by`. `Revision::userModel()` returns the configured user model class.

## SiteSettings

`SiteSettings::current()` returns the settings row, or an empty unsaved instance. See
[Site settings](site-settings.md#helpers) for its methods.
