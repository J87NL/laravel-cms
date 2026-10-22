# Laravel CMS

A block-based CMS core for Laravel. It stores pages in a tree, fills them with typed content blocks, and renders
them server-side with Blade.

The package holds the content model and the public website: pages, blocks, media, menus, revisions, redirects and
site settings. It also owns everything search engines and AI answer engines read: meta and Open Graph tags,
JSON-LD, `sitemap.xml`, `robots.txt` and `llms.txt`. Public pages set no cookies and load no JavaScript.

## How the packages fit together

The CMS is split into two packages. The admin requires the core; the core also works on its own:

| Package | What it does |
|---|---|
| `j87nl/laravel-cms` (this package) | The core: models, actions, validation rules, block types, permissions, public rendering, Blade components, SEO, sitemap, page cache and MCP tools |
| [`j87nl/laravel-vue-cms`](https://github.com/J87NL/laravel-vue-cms) | The admin: Inertia and Vue screens where editors manage pages, blocks, media, menus, redirects and site settings |

The boundary rule: **the core owns the data, the rules, the actions and the public website; the admin owns the
screens and saves through the core's actions.**

This package ships no admin routes and has no frontend dependencies. Because the admin writes through the
[actions](actions.md) and the [models](models.md) of this package, an app can also build its own admin.

Which package to install:

- You want an admin for editors: install `j87nl/laravel-vue-cms`. It requires this package, so Composer installs
  both. Follow the [installation](installation.md) of this package as well, then the
  [admin installation](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/installation.md).
- You only need the public website, or you edit content in code or through the [MCP tools](mcp-tools.md): install
  this package alone.

These docs describe the core. For the admin screens, read the
[laravel-vue-cms documentation](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/index.md).

## How it works

1. Install the package, run the migrations and point `cms.layout` at your Blade layout.
2. Editors create pages and add blocks to them (rich text, images, a gallery, link tiles, a contact form and more).
3. The package registers a catch-all route. A request for `/about-us` renders the published page with that path
   inside your layout, with SEO tags and JSON-LD in the `head` section.
4. Anonymous responses are cached as full HTML and dropped again on every content change.

## Quick example

**Layout** (`resources/views/layouts/site.blade.php`):

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    @yield('head')
</head>
<body>
    <x-cms::menu key="main" />

    <main>@yield('content')</main>

    <x-cms::menu key="footer" />
</body>
</html>
```

**Config** (`config/cms.php`):

```php
'layout' => 'layouts.site',
```

**Content**, created in code with the same actions the admin uses:

```php
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;

$page = app(SavePage::class)->handle(new Page, [
    'title' => 'About us',
], userId: null);

app(SaveBlock::class)->handle($page, new Block, app(BlockRegistry::class)->find('rich_text'), [
    'html' => '<p>We make websites.</p>',
], userId: null);

app(ChangePageStatus::class)->handle($page, PageStatus::Published, userId: null);
```

The page is now public. Because it is the first public page, it also becomes the home page: it is served at `/`,
and `/about-us` redirects there. A second page would be served at its own path.

## Next steps

- [Installation](installation.md) - set up the package
- [Pages](pages.md) - the page tree, statuses and the home page
- [Blocks](blocks.md) - the built-in block types
- [Custom block types](custom-blocks.md) - add your own blocks
- [Rendering](rendering.md) - routes, layout and views
- [MCP tools](mcp-tools.md) - let assistants read and edit content
- [laravel-vue-cms documentation](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/index.md) - the admin screens
  for editors
