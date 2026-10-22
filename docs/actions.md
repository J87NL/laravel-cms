# Actions

Most writes go through action classes in `J87NL\Cms\Actions`. The admin package
[`j87nl/laravel-vue-cms`](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/index.md) and the MCP tools use
them, so an app that writes content the same way gets the same validation, sanitizing, revisions, redirects and cache
invalidation.

Deleting a redirect has no action and is a plain model write (see [Redirects](#redirects)).

Resolve an action from the container and call `handle()`:

```php
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Models\Page;

$page = app(SavePage::class)->handle(new Page, $validated, userId: $request->user()?->id);
```

Actions do not check permissions, see [Permissions](permissions.md). They throw an
`Illuminate\Validation\ValidationException` when a change breaks a rule of the content model, such as a path that is
taken. In a controller, Laravel turns that into the usual validation response. The `$userId` is stored as the author
of the revision and may be `null`.

## Pages

| Action | Signature | Revision |
|---|---|---|
| `SavePage` | `handle(Page $page, array $attributes, ?int $userId): Page` | Yes |
| `MovePage` | `handle(Page $page, Page $target, string $position, ?int $userId): void` | Yes |
| `ChangePageStatus` | `handle(Page $page, PageStatus $status, ?int $userId): void` | Yes |
| `PublishPage` | `handle(Page $page, ?int $userId): void` | Yes |
| `DiscardPendingRevision` | `handle(Page $page): bool` | No |
| `DiffPendingRevision` | `handle(Page $page, ?Revision $revision = null, bool $withWords = true): ?array` | No |
| `MakeHomePage` | `handle(Page $page): void` | No |
| `SchedulePage` | `handle(Page $page, ?Carbon $publishAt, ?Carbon $unpublishAt): void` | No |
| `DeletePage` | `handle(Page $page): void` | No |

Helpers for an admin that wants to disable a button beforehand:

- `DeletePage::blockedReason(Page $page): ?string`
- `ChangePageStatus::unpublishBlockedReason(Page $page): ?string`
- `MakeHomePage::blockedReason(Page $page): ?string` (an instance method)

`PublishPage` applies the page's pending revision and publishes the page when it is not published yet, see
[Pending revisions](revisions.md#pending-revisions). `DiscardPendingRevision` returns `false` when there was no pending
revision. `DiffPendingRevision` returns what publishing the pending revision would change, or `null` without one, see
[Comparing a pending revision](revisions.md#comparing-a-pending-revision-with-the-live-page). `ChangePageStatus::applyLocked(Page $locked, PageStatus $status, ?int $userId)` changes the status of a page
whose row the caller has already locked, inside the caller's transaction and home page lock.

`SavePage::apply(Page $page, array $attributes): void` saves without recording a revision. `MovePage` takes
`MovePage::BEFORE`, `MovePage::AFTER` or `MovePage::INSIDE` as position; anything else throws an
`InvalidArgumentException`.

See [Pages](pages.md) and [Scheduling](scheduling.md).

## Blocks

| Action | Signature | Revision |
|---|---|---|
| `SaveBlock` | `handle(Page $page, Block $block, BlockType $type, array $data, ?int $userId): Block` | Yes |
| `DeleteBlock` | `handle(Block $block, ?int $userId): void` | Yes |
| `ToggleBlockVisibility` | `handle(Block $block, ?int $userId): void` | Yes |
| `ReorderBlocks` | `handle(Page $page, array $blockIds, ?int $userId): void` | Yes |
| `ReorderBlocks` | `move(Page $page, int $blockId, int $toBlockId, ?int $userId): void` | Yes |

`ReorderBlocks::move()` throws an `InvalidArgumentException` when either block is not on the page. A reorder that
keeps the current order changes nothing and records no revision.

`SaveBlock` does not validate: validate with `$type->rules()` first. See [Blocks](blocks.md).

## Revisions

| Action | Signature |
|---|---|
| `RecordRevision` | `handle(Page $page, ?int $userId): Revision` |
| `RestoreRevision` | `handle(Revision $revision, ?int $userId): Page` (refuses a pending revision) |

`RecordRevision::snapshot(Page $page): array` returns the snapshot without storing it, and `RecordRevision::keep()`
the number of revisions kept per page (`cms.revisions.keep`, at least 1). See [Revisions](revisions.md).

## Media

| Action | Signature |
|---|---|
| `UploadMedia` | `handle(UploadedFile $file, array $attributes): MediaAsset` |
| `UpdateMedia` | `handle(MediaAsset $asset, array $attributes): MediaAsset` |
| `ReplaceMediaFile` | `handle(MediaAsset $asset, UploadedFile $file): MediaAsset` |
| `DeleteMedia` | `handle(MediaAsset $asset): void` |

`UploadMedia` refuses a file larger than `cms.media.max_upload_kilobytes`. `UpdateMedia` changes the alt text, caption
and focal point and validates them with `MediaRules`. `ReplaceMediaFile` gives an asset a new file with the same checks
as `UploadMedia`, keeps its id and details, and deletes the old files after the transaction commits. See
[Media](media.md).

## Menus

| Action | Signature |
|---|---|
| `SaveMenuItem` | `handle(Menu $menu, MenuItem $item, ?int $pageId, ?string $url, ?string $label, ?int $parentId): MenuItem` |
| `ReorderMenuItems` | `move(Menu $menu, int $itemId, int $toItemId): void` |
| `DeleteMenuItem` | `handle(Menu $menu, MenuItem $item): void` |

`ReorderMenuItems` and `DeleteMenuItem` throw an `InvalidArgumentException` when an item belongs to another menu. See
[Menus](menus.md).

## Redirects

| Action | Signature |
|---|---|
| `SaveRedirect` | `handle(Redirect $redirect, string $from, string $to, int $statusCode, RedirectSource $source): Redirect` |
| `ImportRedirects` | `handle(string $file): ImportResult` |

`SaveRedirect` also has `forPathChange(string $oldPath, string $newPath): void`, used for the automatic redirects, and
`releasePath(string $path): void`, which deletes the redirect from a path. Delete a redirect by deleting the model.
See [Redirects](redirects.md).

## Site settings

| Action | Signature |
|---|---|
| `SaveSiteSettings` | `handle(array $attributes): SiteSettings` |

See [Site settings](site-settings.md).

## Validation rules

Three classes in `J87NL\Cms\Validation` hold the request rules that the admin and the MCP tools share. Each has
`fields(): array` with all rules and `for(string $field): array` with the rules of one field.

- `PageRules`: `title`, `slug`, `parent_id`, `menu_title`, `meta_title`, `meta_description`, `og_image_id`,
  `canonical_url`, `noindex` and `schema_type`. The rules for `title` and `noindex` have no presence rule, so add
  `required` or `sometimes` yourself. `canonical_url` allows 255 characters, the size of its column. `schema_type`
  only accepts a type from `cms.schema_types`; `PageRules::schemaTypes()` returns that list.
- `MediaRules`: `alt`, `caption`, `focal_x` and `focal_y` (whole percentages from 0 to 100).
- `RedirectRules`: `from_path`, `to` and `status_code`.
