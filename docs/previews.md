# Previews

A preview shows a page of any status, a draft included, as it would look on the website. Previews use signed URLs,
so the editor can share the link without logging in. The page editor of `j87nl/laravel-vue-cms` shows this link,
see [Publication](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/publication.md#preview) in its docs.

## Creating a preview link

```php
use J87NL\Cms\Http\Controllers\PreviewController;

$url = PreviewController::url($page);
```

`PreviewController::url(Page $page): string` returns a temporary signed URL for the route `cms.preview`
(`GET /cms/preview/{page}`). The link is valid for `cms.preview.minutes` minutes, 60 by default. A link with a
missing, changed or expired signature answers `403`.

## What a preview renders

The page renders in your layout, with its blocks, exactly like the public page, with these differences:

- the response has `X-Robots-Tag: noindex, nofollow` and `Cache-Control: no-store, private`;
- the page has `<meta name="robots" content="noindex, nofollow">`;
- the response is never stored in the [page cache](page-cache.md).

Hidden blocks are not shown in a preview either.
