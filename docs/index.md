# Laravel CMS

A block-based CMS core for Laravel. It stores pages in a tree, fills them with typed content blocks, and renders
them server-side with Blade.

The package holds the content model and the public website: pages, blocks, media, menus, revisions, redirects and
site settings. It also owns everything search engines and AI answer engines read: meta and Open Graph tags,
JSON-LD, `sitemap.xml`, `robots.txt` and `llms.txt`. Public pages set no cookies and load no JavaScript.

The admin is not part of this package. It lives in [`j87nl/laravel-vue-cms`](https://github.com/J87NL/laravel-vue-cms)
(Inertia and Vue). This package has no frontend dependencies. Everything the admin does goes through the action
classes described in [Actions](actions.md), so an app can also build its own admin.

## How it works

1. Install the package, run the migrations and point `cms.layout` at your Blade layout.
2. Editors create pages and add blocks to them (rich text, images, a gallery, a contact form and more).
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
