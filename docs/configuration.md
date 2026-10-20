# Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=cms-config
```

This creates `config/cms.php`. The package reads no environment variables of its own: every value lives in this
file. Use `env()` in your published copy if you want a value per environment.

The package also reads a few Laravel settings: `app.key` (contact form tokens), `app.url` (recognizing full URLs on
this site in redirects), `app.name` (site name fallback), `app.locale` (language in JSON-LD and `og:locale`),
`session.cookie` (cache bypass) and `auth.providers.users.model` (revision authors).

The admin of `j87nl/laravel-vue-cms` reads some of these keys too, such as `menus`, `preview.minutes`,
`blocks.disabled` and `media.max_upload_kilobytes`. Its own settings live in `config/vue-cms.php`, see its
[configuration](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/configuration.md).

## General

| Key | Default | Purpose |
|---|---|---|
| `permissions_config_key` | `'j87nl.permissions'` | Config key the CMS permission names are pushed into, see [Permissions](permissions.md) |
| `layout` | `'cms::layouts.default'` | Blade layout every public page extends |
| `fallback_home_view` | `null` | View shown at `/` while no home page is published. `null` shows the not-found page |
| `user_model` | `null` | Model for the author of a revision. `null` uses `auth.providers.users.model` |

## Routes

```php
'routes' => [
    'enabled' => true,
    'home_name' => 'cms.home',
    'middleware' => [],
],
```

| Key | Default | Purpose |
|---|---|---|
| `routes.enabled` | `true` | Register the public routes. With `false` the package registers no routes at all |
| `routes.home_name` | `'cms.home'` | Route name of `/` |
| `routes.middleware` | `[]` | Middleware added in front of every package route |

The public routes run without session middleware by default, so they set no cookies. See
[Rendering](rendering.md#routes) for the list of routes.

## Blocks and menus

```php
'blocks' => [
    'disabled' => [],
],

'menus' => [
    'main',
    'footer',
],
```

| Key | Default | Purpose |
|---|---|---|
| `blocks.disabled` | `[]` | Block type keys to hide on this site, for example `['gallery']` |
| `menus` | `['main', 'footer']` | The menu keys editors can fill. The MCP tools only accept these keys |

A disabled block type is left out of the block registry, so it is not rendered, cannot be added through the MCP
tools and, for the contact form, accepts no submissions. See
[Blocks](blocks.md#disabling-block-types) and [Menus](menus.md).

## Revisions

| Key | Default | Purpose |
|---|---|---|
| `revisions.keep` | `20` | Revisions kept per page. Older ones are pruned. The minimum is 1 |

## Page cache

```php
'cache' => [
    'enabled' => true,
    'store' => null,
    'ttl' => 86400,
],
```

| Key | Default | Purpose |
|---|---|---|
| `cache.enabled` | `true` | Switch the full-page cache on or off |
| `cache.store` | `null` | Cache store for the cached pages, for example `'redis'`. `null` uses the default store |
| `cache.ttl` | `86400` | Lifetime of a cached page in seconds |

See [Page cache](page-cache.md).

## Preview and scheduling

| Key | Default | Purpose |
|---|---|---|
| `preview.minutes` | `60` | Lifetime of a signed preview link |
| `scheduling.enabled` | `true` | Schedule `cms:publish-scheduled` every minute |

See [Previews](previews.md) and [Scheduling](scheduling.md).

## robots.txt

```php
'robots' => [
    'disallow' => [
        '/admin',
    ],
],
```

Each path becomes a `Disallow:` line under `User-agent: *`. See [robots.txt and llms.txt](robots-and-llms.md).

## Security headers

```php
'security_headers' => [
    'enabled' => true,
    'hsts' => true,
    'nosniff' => true,
    'referrer_policy' => true,
    'permissions_policy' => true,
    'hsts_max_age' => 31536000,
    'csp' => [],
],
```

| Key | Default | Purpose |
|---|---|---|
| `security_headers.enabled` | `true` | Add the headers below. `false` adds none of them, the CSP included |
| `security_headers.hsts` | `true` | `Strict-Transport-Security` on secure requests |
| `security_headers.nosniff` | `true` | `X-Content-Type-Options: nosniff` |
| `security_headers.referrer_policy` | `true` | `Referrer-Policy: strict-origin-when-cross-origin` |
| `security_headers.permissions_policy` | `true` | `Permissions-Policy` that denies camera, microphone, geolocation and interest cohorts |
| `security_headers.hsts_max_age` | `31536000` | `max-age` of the HSTS header in seconds |
| `security_headers.csp` | `[]` | Extra sources per CSP directive |

See [Security](security.md#security-headers).

## Contact form

```php
'contact' => [
    'recipient' => null,
    'per_hour' => 5,
    'global_per_hour' => 30,
    'min_seconds' => 3,
    'max_seconds' => 172800,
],
```

| Key | Default | Purpose |
|---|---|---|
| `contact.recipient` | `null` | Fallback address when the site settings have no contact recipient |
| `contact.per_hour` | `5` | Submissions per IP address per hour |
| `contact.global_per_hour` | `30` | Mails sent per hour for the whole site |
| `contact.min_seconds` | `3` | A form posted sooner after rendering counts as a bot |
| `contact.max_seconds` | `172800` | A form posted later than this after rendering counts as a bot (48 hours) |

See [Contact form](contact-form.md).

## Media

```php
'media' => [
    'disk' => 'public',
    'max_upload_kilobytes' => 10240,
    'max_pixels' => 40_000_000,
    'widths' => [
        480,
        960,
        1600,
    ],
],
```

| Key | Default | Purpose |
|---|---|---|
| `media.disk` | `'public'` | Filesystem disk of the media collection |
| `media.max_upload_kilobytes` | `10240` | Not read by this package. `UploadMedia` does not check the file size, so validate it where you accept the upload. The media screen of `j87nl/laravel-vue-cms` uses it as its upload limit |
| `media.max_pixels` | `40000000` | Largest image in pixels (width times height) that an upload may have |
| `media.widths` | `[480, 960, 1600]` | Widths of the WebP conversions |

See [Media](media.md).

## Embeds

```php
'embeds' => [
    'youtube' => [
        'hosts' => [
            'www.youtube-nocookie.com',
        ],
        'path_pattern' => '#^/embed/[A-Za-z0-9_-]{11}$#',
    ],
    'openstreetmap' => [
        'hosts' => [
            'www.openstreetmap.org',
        ],
        'path_pattern' => '#^/export/embed\.html$#',
    ],
],
```

The providers the embed block accepts. A URL must use `https`, match one of the `hosts` exactly and have a path that
matches `path_pattern`. The same hosts are added to the CSP `frame-src` of a page that contains such an embed. See
[Blocks](blocks.md#embed) and [Security](security.md#embeds).

## Rich text

```php
'rich_text' => [
    'editor' => 'hugerte',
    'languages' => [
        'nl',
    ],
    'editor_options' => [
        'menubar' => false,
        'plugins' => 'autolink link lists table',
        // ...
    ],
],
```

| Key | Default | Purpose |
|---|---|---|
| `rich_text.editor` | `'hugerte'` | Editor key set on the rich text fields (a `Wysiwyg` field of `tranquil-tools/laravel-form-builder`) |
| `rich_text.languages` | `['nl']` | Locales the editor has a language pack for. When the app locale is in this list, it is passed as the `language` option |
| `rich_text.editor_options` | see the config file | Options passed to the editor: toolbar, plugins, block formats, valid elements and table settings |

These keys only shape the editor in the admin. What is stored and rendered is decided by the sanitizer, see
[Security](security.md#rich-text). The admin's Vue app must register an editor under the `rich_text.editor` key, see
[Rich text editor](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/installation.md#10-rich-text-editor) in the
laravel-vue-cms installation.
