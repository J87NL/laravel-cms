# Sitemap

The package serves `/sitemap.xml` (route `cms.sitemap`) with the `application/xml; charset=UTF-8` content type. The
response goes through the [page cache](page-cache.md). `robots.txt` points to it.

## Pages in the sitemap

`J87NL\Cms\Sitemap\PageSitemapContributor` adds every page that is:

- published, with all its ancestors published;
- not marked `noindex`;
- without a `canonical_url` that points to another URL.

The home page comes first, then the other pages sorted by path. `lastmod` is the latest of the page's `updated_at`
and the `updated_at` of its blocks.

## Adding URLs

Apps add their own URLs, for example for routes outside the CMS, with a sitemap contributor. A contributor
implements `J87NL\Cms\Sitemap\SitemapContributor` and yields `SitemapUrl` objects:

```php
<?php

declare(strict_types=1);

namespace App\Sitemap;

use App\Models\Event;
use J87NL\Cms\Sitemap\SitemapContributor;
use J87NL\Cms\Sitemap\SitemapUrl;

class EventSitemapContributor implements SitemapContributor
{
    public function urls(): iterable
    {
        foreach (Event::query()->cursor() as $event) {
            yield new SitemapUrl(route('events.show', $event), $event->updated_at);
        }
    }
}
```

`SitemapUrl` takes the full URL as `loc` and an optional `CarbonInterface` as `lastmod`.

Register the contributor in a service provider:

```php
use App\Sitemap\EventSitemapContributor;
use J87NL\Cms\Facades\Cms;

public function boot(): void
{
    Cms::sitemap()->contribute(EventSitemapContributor::class);
}
```

`contribute()` takes a class name, which is resolved from the container when the sitemap is built, or an instance.
A URL that an earlier contributor already listed is skipped.

Because the sitemap is cached, a change in your own models does not update it. Flush the page cache when those
models change, for example with `app(J87NL\Cms\Cache\PageCache::class)->flush()` in an observer.
