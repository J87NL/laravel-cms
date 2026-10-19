# j87nl/laravel-cms

Block-based CMS core for Laravel: pages in a tree, typed content blocks, media, menus, revisions, redirects and site settings, rendered server-side with Blade. Public pages set no cookies and load no JavaScript, and the package owns everything search engines and AI answer engines read: meta and Open Graph tags, JSON-LD, `sitemap.xml`, `robots.txt` and `llms.txt`.

The admin lives in a separate package, [`j87nl/laravel-vue-cms`](https://github.com/J87NL/laravel-vue-cms) (Inertia and Vue). This package has no frontend dependencies.

## Requirements

- PHP 8.5 and Laravel 13
- `spatie/laravel-medialibrary` 11 (installed as a dependency) with its `media` table
- A queue worker for the WebP conversions, and the scheduler for planned publishing

## Installation

```bash
composer require j87nl/laravel-cms
php artisan vendor:publish --tag=cms-config
php artisan migrate
```

Point `cms.layout` at your public Blade layout. The layout yields `title`, `head` and `content`:

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

The package registers a catch-all route last, so application routes always win. Public routes run without session middleware.

## What it does

- **Pages:** a tree with draft, published and unpublished states, one public home page, revisions with restore, and planned publishing (`cms:publish-scheduled` runs every minute through the scheduler).
- **Blocks:** rich text (HugeRTE, sanitized on save and on render), text with image, image, gallery, call to action, embed (YouTube no-cookie and OpenStreetMap only), dates (emitting schema.org `Event`) and a contact form.
- **Redirects:** a 301 for every path change, for the page and its subtree, with chains collapsed to one hop; manual redirects and CSV import.
- **Findability:** title pattern, meta description with a fallback to the first text block, canonical URL, Open Graph and Twitter tags, JSON-LD (`Person` or `Organization`, `WebSite`, the page, `BreadcrumbList`, `Event`), `sitemap.xml`, `robots.txt` and `llms.txt`.
- **Preview:** a signed link, valid for an hour, that renders a draft with `noindex`.
- **Cache:** anonymous GET responses are cached as full HTML per path and invalidated on every content, menu, media or settings change. Requests with a session cookie or a query string bypass the cache; the `X-Cms-Cache` header says `hit`, `miss` or `bypass`.
- **Security headers:** a strict Content-Security-Policy (frames only from the embed hosts, on pages with an embed), HSTS on secure requests, `Referrer-Policy` and `X-Content-Type-Options`.
- **Contact form:** mails the address from the site settings (or `cms.contact.recipient`) with the visitor as Reply-To, protected by a honeypot, a signed timestamp and a rate limit instead of CSRF.

## Extending

Register your own block types and sitemap URLs in a service provider:

```php
use J87NL\Cms\Facades\Cms;

Cms::blocks()->register(SignupBlock::class);
Cms::sitemap()->contribute(EventSitemapContributor::class);
```

A block type implements `J87NL\Cms\Blocks\BlockType` (or extends `AbstractBlockType`) and may implement `HasJsonLd` and `ReferencesMedia`. A sitemap contributor implements `J87NL\Cms\Sitemap\SitemapContributor` and yields `SitemapUrl` objects.

Permissions are pushed into the config key named by `cms.permissions_config_key`, so the app can map them to its roles: `cms.pages.edit`, `cms.pages.publish`, `cms.menus`, `cms.media`, `cms.redirects` and `cms.settings`.

### Public styles

The package ships no CSS. The contact form hides its honeypot field with the class `cms-contact__trap`; your site stylesheet must hide it, for example:

```css
.cms-contact__trap { position: absolute; left: -10000px; }
```

The image focal point is an inline `object-position` style, so the Content-Security-Policy allows `style-src-attr 'unsafe-inline'`.

## Configuration

See [`config/cms.php`](config/cms.php). The most relevant keys:

| Key | Default | Purpose |
| --- | --- | --- |
| `layout` | `cms::layouts.default` | The public Blade layout |
| `fallback_home_view` | `null` | View for `/` while no home page is published |
| `cache.store` | default store | Cache store for the full-page cache, for example `redis` |
| `cache.enabled` | `true` | Switch the full-page cache off |
| `contact.recipient` | `null` | Fallback address when the site settings have none |
| `blocks.disabled` | `[]` | Block type keys to hide on this site |

## Testing

```bash
composer update
vendor/bin/pest
```

## License

MIT
