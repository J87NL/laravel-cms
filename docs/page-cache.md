# Page cache

Anonymous `GET` responses of the public routes are cached as full HTML, so a cached page costs no database queries.
The cache is switched on by default.

## What is cached

`J87NL\Cms\Http\Middleware\CachePublicResponses` runs on `/`, the page fallback route, `sitemap.xml`, `robots.txt`
and `llms.txt`. A request is served from the cache, or stored in it, when:

- `cms.cache.enabled` is `true`;
- the method is `GET` or `HEAD`;
- the URL has no query string;
- the request has no session cookie (the cookie named in `session.cookie`).

A response is only stored when it is a `200` to a `GET`, sets no cookies and has no `Cache-Control: no-store`. A
`404` or a redirect is never stored. The cached entry keeps the body, the status and the `Content-Type` and
`X-Robots-Tag` headers.

The cache key is the host and the path. Entries live in `cms.cache.store` (the default store when `null`) for
`cms.cache.ttl` seconds.

The [security headers](security.md#security-headers) are added after the cache, so a cached page gets fresh headers.

A cached page with a contact form contains the form token from the moment it was rendered. Keep `cms.cache.ttl` below
`cms.contact.max_seconds`, see [Contact form](contact-form.md#the-token). Saving the site settings flushes the cache, so
switching [Turnstile](contact-form.md#cloudflare-turnstile) on or off there applies at once. An app that serves the
Turnstile keys from its own settings must flush the cache itself.

## The X-Cms-Cache header

| Value | Meaning |
|---|---|
| `hit` | Served from the cache |
| `miss` | Rendered and stored |
| `bypass` | Not cacheable (query string, session cookie, or not a `GET` or `HEAD`) |

A rendered response that could not be stored, such as a `404`, gets no header. With the cache switched off, no
header is sent.

## Invalidation

Every save or delete of these models drops all cached pages:

- `Page`, `Block`, `Menu`, `MenuItem`, `SiteSettings` and `MediaAsset`;
- `Spatie\MediaLibrary\MediaCollections\Models\Media`, but only the items of a `MediaAsset`. Media of other models
  in your app leaves the CMS page cache alone.

The flush waits until the database transaction commits, and a transaction that changes many models, such as a
reorder of all blocks of a page, flushes once. A rolled back transaction flushes nothing. The flush works by raising a
version number stored in the cache, so old entries are no longer read and expire with their TTL.

Changes in code are not detected. After a deploy that changes the layout, a view, a translation or a block type, flush
the cache:

```bash
php artisan cms:flush-page-cache
```

The same flush is available in code as `app(J87NL\Cms\Cache\PageCache::class)->flush()`.

## Switching it off

```php
'cache' => [
    'enabled' => false,
],
```

Use this during development, or when a reverse proxy caches the pages instead.
