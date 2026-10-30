# j87nl/laravel-cms

Block-based CMS core for Laravel: pages in a tree, typed content blocks, media, menus, revisions, redirects and site settings, rendered server-side with Blade. Public pages set no cookies and load no JavaScript, and the package owns everything search engines and AI answer engines read: meta and Open Graph tags, JSON-LD, `sitemap.xml`, `robots.txt` and `llms.txt`.

The admin lives in a separate package, [`j87nl/laravel-vue-cms`](https://github.com/J87NL/laravel-vue-cms) (Inertia and Vue). This package has no frontend dependencies.

## Requirements

- PHP 8.5 and Laravel 13
- `laravel/mcp` 0.9.7 or later in 0.9, or 1.1.1 or later (installed as a dependency); older releases miss security fixes
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

Cached public pages are only invalidated by content changes. Run `php artisan cms:flush-page-cache` after every deploy, so a changed layout or view reaches visitors.

## What it does

- **Pages:** a tree with draft, published and unpublished states, one public home page, revisions with restore, and planned publishing (`cms:publish-scheduled` runs every minute through the scheduler).
- **Blocks:** rich text (HugeRTE, sanitized on save and on render), text with image, image, gallery, call to action, embed (YouTube no-cookie and OpenStreetMap only), dates (emitting schema.org `Event`), a contact form, and link tiles (sponsors or social profiles, with a configurable `rel`).
- **Redirects:** a 301 for every path change, for the page and its subtree, with chains collapsed to one hop; manual redirects and CSV import.
- **Findability:** title pattern, meta description with a fallback to the first text block, canonical URL, Open Graph and Twitter tags, JSON-LD (`Person` or `Organization`, `WebSite`, the page, `BreadcrumbList`, `Event`), `sitemap.xml`, `robots.txt` and `llms.txt`.
- **Preview:** a signed link, valid for an hour, that renders a draft with `noindex`, and one, for signed-in users with `cms.pages.edit` only, that renders a published page with its pending revision applied.
- **Pending revision diff:** `DiffPendingRevision` compares a pending revision with the live page (fields, added, removed, changed and moved blocks, word-level text changes, markup-only and link target changes); `page_get` returns a summary of it.
- **Cache:** anonymous GET responses are cached as full HTML per path and invalidated on every content, menu, media or settings change. Requests with a session cookie or a query string bypass the cache; the `X-Cms-Cache` header says `hit`, `miss` or `bypass`.
- **Security headers:** a strict Content-Security-Policy (frames only from the embed hosts, on pages with an embed, and Cloudflare Turnstile's script and frame only on pages with its widget), HSTS on secure requests, `Referrer-Policy` and `X-Content-Type-Options`.
- **Contact form:** mails the address from the site settings (or `cms.contact.recipient`) with the visitor as Reply-To, protected by a honeypot, a signed timestamp and rate limits instead of CSRF, with spam checks on links and blocked keywords, optional Cloudflare Turnstile (keys in the site settings, the secret encrypted) and optional rspamd scoring.

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

## MCP tools via the J87 MCP hub

The package ships CMS tools on [`laravel/mcp`](https://github.com/laravel/mcp), meant to be offered to assistants through the J87 MCP hub. The hub adds the app prefix (for example `j87_`), so the tool names here are short and unprefixed.

| Tool | Read-only | Destructive | Permission |
| --- | --- | --- | --- |
| `pages_list` | yes | no | `cms.pages.edit` |
| `page_get` | yes | no | `cms.pages.edit` |
| `media_list` | yes | no | `cms.media` |
| `media_upload` | no | no | `cms.media` |
| `media_upload_start` | no | no | `cms.media` |
| `media_upload_chunk` | no | no | `cms.media` |
| `media_upload_finish` | no | no | `cms.media` |
| `media_delete` | no | yes | `cms.media` |
| `menu_items_list` | yes | no | `cms.menus` |
| `redirects_list` | yes | no | `cms.redirects` |
| `page_draft_create` | no | no | `cms.pages.edit` |
| `page_update` | no | no | `cms.pages.edit` |
| `block_add` | no | no | `cms.pages.edit` |
| `block_update` | no | no | `cms.pages.edit` |
| `block_remove` | no | yes | `cms.pages.edit` |
| `blocks_reorder` | no | no | `cms.pages.edit` |
| `page_update_live` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `block_add_live` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `block_update_live` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `block_remove_live` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `blocks_reorder_live` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `page_publish` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `page_unpublish` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `revision_discard` | no | yes | `cms.pages.edit`, `cms.pages.publish` |
| `menu_item_save` | no | yes | `cms.menus` |
| `redirect_save` | no | no | `cms.redirects` |
| `redirect_delete` | no | yes | `cms.redirects` |

- No tool schedules, makes a home page, deletes pages or replaces the file of an image. Scheduling stays in the CMS admin.
- `media_delete` permanently deletes images that are not in use (blocks, page OG images, pending revisions, site settings), each id on its own, and reports `deleted`, `in_use`, `not_found` or `failed` per id. It is marked destructive.
- `media_upload` adds a PNG, JPEG, WebP or GIF image from base64 content or a public https `source_url` through `UploadMedia`, with the same size limit, alt text rules and re-encoding as the admin. The type is read from the content, never from the file name, so SVG is refused. An optional `sha256` turns a miscopied base64 string into a clear "arrived damaged" error. `media_upload_start`, `media_upload_chunk` and `media_upload_finish` upload a file in checksummed chunks, so a damaged chunk is resent on its own. See [MCP tools](docs/mcp-tools.md#uploading-images).
- The draft tools (`page_update`, `block_add`, `block_update`, `block_remove`, `blocks_reorder`) change a draft or unpublished page directly. On a published page they collect the changes in one pending revision; the live page stays as it is until `page_publish` or the admin publishes it. Publishing applies only what the pending revision changed, so live changes made in the meantime stay. `page_publish`, `page_unpublish` and `revision_discard` also check the `cms.pages.write` gate. The `_live` tools change the page directly, also when it is published, and need `cms.pages.publish`. See [MCP tools](docs/mcp-tools.md#draft-and-live-mode).
- Pages with a planned publication (`publish_at`) are read-only for every page and block tool. When the app defines the `cms.pages.write` gate (as `j87nl/laravel-vue-cms` does), direct writes check it as well; draft writes into a pending revision do not.
- Writes go through the same actions and validation rules as the admin (`J87NL\Cms\Validation\PageRules` and `RedirectRules`), so validation, sanitizing, revisions and cache invalidation are the same. Every tool that changes what visitors see is marked destructive (the `_live` tools, `page_publish`, `page_unpublish`, `menu_item_save` and `redirect_delete`), like `block_remove`, `revision_discard` and `media_delete`, which throw content away: the hub starts destructive tools disabled until an admin enables them.
- Every tool runs as the authenticated user and checks the CMS permission above. A missing user or permission returns an error result with a sentence, never an exception.
- Only a request with the header `X-J87-Grant-Level: full` may run tools that are not read-only. A missing header, `read` or any other value only allows the read-only tools.

Register the tools on the app's own MCP server in one line. Every tool description is self-contained, because the hub does not pass server instructions to clients. `Cms::mcpInstructions()` returns a summary for the server's `instructions`, which only helps clients that connect to the app directly, not through the hub:

```php
use J87NL\Cms\Facades\Cms;
use Laravel\Mcp\Server;

class SiteServer extends Server
{
    protected function boot(): void
    {
        $this->tools = [
            ...$this->tools,
            ...Cms::mcpTools(),
        ];

        $this->instructions = sprintf("%s\n\n%s", $this->instructions, Cms::mcpInstructions());
    }
}
```

The package ships 27 tools, more than the 15 that `laravel/mcp` lists per `tools/list` page by default. Set `public int $defaultPaginationLength = 50;` on the server when the hub does not follow `nextCursor`.

`boot()` runs before every request is handled. Keep the server's own instructions in the `$instructions` property: an `#[Instructions]` attribute takes precedence over the property and would drop the CMS guidance.

The app provides the rest of the hub's downstream contract, described in the hub's `docs/adding-an-app.md`:

- an MCP endpoint at `/internal/mcp` that is only reachable on loopback (or WireGuard), for example `Mcp::web('/internal/mcp', SiteServer::class)`;
- middleware that checks the hub's service token (`Authorization: Bearer …`, compared with `hash_equals`), and a named rate limiter for the route;
- middleware that authenticates the `X-J87-Acting-User` header as the app's user on `tools/call` (`Auth::setUser()`), and refuses a missing or unknown user;
- the CMS permissions mapped to the app's roles;
- a `hub:notify-tools-changed` command that the deploy runs, so the hub syncs the tool list.

## Configuration

See [`config/cms.php`](config/cms.php). The most relevant keys:

| Key | Default | Purpose |
| --- | --- | --- |
| `layout` | `cms::layouts.default` | The public Blade layout |
| `fallback_home_view` | `null` | View for `/` while no home page is published |
| `cache.store` | default store | Cache store for the full-page cache, for example `redis` |
| `cache.enabled` | `true` | Switch the full-page cache off |
| `contact.recipient` | `null` | Fallback address when the site settings have none |
| `contact.per_minute` | `3` | Sent messages per IP address per contact block per minute |
| `contact.max_links` | `2` | A message with more links is treated as spam |
| `contact.blocked_keywords` | `[]` | Words that mark a message as spam |
| `contact.rspamd.url` | `CMS_CONTACT_RSPAMD_URL` | rspamd `checkv2` endpoint; empty skips rspamd |
| `contact.turnstile.timeout` | `3` | Seconds to wait for Cloudflare Turnstile; its keys live in the site settings |
| `blocks.disabled` | `[]` | Block type keys to hide on this site |

## Testing

```bash
composer update
vendor/bin/pest
```

## License

MIT
