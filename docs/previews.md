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

## Previewing a pending revision

A published page can have a pending revision (see [Pending revisions](revisions.md#pending-revisions)). Its preview
shows the page as it would look after publishing it:

```php
use J87NL\Cms\Http\Controllers\PreviewController;

$url = PreviewController::pendingUrl($page, $page->pendingRevision);
```

`PreviewController::pendingUrl(Page $page, Revision $revision): string` returns a temporary signed URL for the route
`cms.preview.pending` (`GET /cms/preview/{page}/pending/{revision}`), valid for `cms.preview.minutes` minutes like the
draft preview. The response has the same `noindex` headers and meta tag and is never cached.

Unlike the draft preview, the link alone is not enough:

- the route runs `cms.preview.pending_middleware` (default `web` and `auth`), then `can:cms.pages.edit`, then
  `signed`. A guest is sent to the login page (or gets a 401 for JSON), a user without `cms.pages.edit` gets a 403;
- the revision id is part of the signature. When it is no longer the page's pending revision (published, discarded,
  or replaced by a newer one) the link answers 404, so it never shows changes made after it was handed out.

The preview applies the pending revision in memory, in the same way as publishing does
(`PendingRevisionApplier::plan()`), so changes made on the live page that the pending revision left alone are shown
too. Nothing is written to the database. Blocks added in the
pending revision do not exist yet, so a contact form among them cannot be sent from the preview.

The block renderer and the SEO tags read the blocks through `Page::visibleBlocks()`, which uses the page's loaded
`blocks` relation when it is loaded. A layout that renders blocks itself should do the same to show the pending blocks.
