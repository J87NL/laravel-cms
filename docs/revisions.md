# Revisions

Every change to a page or its blocks stores a snapshot of the page in `cms_revisions`, as a
`J87NL\Cms\Models\Revision`. A revision can be restored.

A published page can also have one pending revision: changes that are not live yet, see
[Pending revisions](#pending-revisions).

Editors list and restore revisions in the page editor of `j87nl/laravel-vue-cms`, see
[Revisions](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/revisions.md) in its docs.

## What is recorded

These actions record a revision after their change:

- `SavePage`, `MovePage` and `ChangePageStatus`;
- `SaveBlock`, `DeleteBlock`, `ToggleBlockVisibility` and `ReorderBlocks`;
- `RestoreRevision`;
- `PublishPage`.

`SchedulePage`, `MakeHomePage` and `DeletePage` do not record a revision.

The snapshot holds the page's content fields (`Page::CONTENT_ATTRIBUTES`), its `status` and `is_home`, and every
block with its `id`, `type`, `data`, `sort_order` and `is_visible`. Snapshots recorded before 0.3 have no block `id`. The `created_by` column holds the `$userId` passed to
the action, or `null`.

## Pruning

Only the latest `cms.revisions.keep` revisions are kept per page (20 by default, at least 1). Older ones are deleted
when a new one is recorded. A pending revision is never pruned and does not count towards the limit.

## Restoring

```php
use J87NL\Cms\Actions\RestoreRevision;

$page = app(RestoreRevision::class)->handle($revision, userId: auth()->id());
```

`RestoreRevision::handle(Revision $revision, ?int $userId): Page`:

1. writes the content fields of the snapshot back to the page (the status and home page are left as they are);
2. keeps the current parent when the parent in the snapshot no longer exists;
3. replaces all blocks of the page with the blocks of the snapshot;
4. records a new revision.

Path checks apply as for any save, so a restore that would put the page on a path that is taken throws a
`ValidationException`. A pending revision cannot be restored this way; `RestoreRevision` throws a
`ValidationException` for it. Publish or discard it instead.

## Listing revisions

```php
$page->revisions; // newest first, without the pending revision
$page->pendingRevision; // the pending revision, or null
$revision->author; // the user who made it, or null
```

`Revision::query()->history()` and `Revision::query()->pending()` select either kind.

## Pending revisions

A pending revision is a `Revision` with `is_pending` set. It holds the page fields and blocks as they will be after
publishing, in the same snapshot format, and a block keeps its live `id` there. Blocks that only exist in the pending
revision have a negative id. Under `pending` the snapshot also keeps `base` (the live page when the revision started),
`edited_by` (the user of the last change) and `next_new_id`. The public page, the page cache and the revision history
ignore it. Only a published page has one: taking a page offline applies it to the page.

The MCP draft tools create and change it, see [MCP tools](mcp-tools.md#draft-and-live-mode). In code,
`J87NL\Cms\Pages\PendingRevisionEditor` does the same: `updatePage()`, `addBlock()`, `updateBlock()`,
`removeBlock()`, `reorderBlocks()`, `find()` and `discard()`. Call these inside a transaction that locks the page row,
as the tools do.

```php
use J87NL\Cms\Actions\DiscardPendingRevision;
use J87NL\Cms\Actions\PublishPage;

app(PublishPage::class)->handle($page, userId: auth()->id());
app(DiscardPendingRevision::class)->handle($page);
```

`PublishPage` applies what the pending revision changed compared with its base (`J87NL\Cms\Pages\PendingRevisionApplier`):
changed fields and blocks are written, blocks are matched by id, new ones are created and removed ones deleted, and
everything the pending revision left alone keeps its live value. It then deletes the pending revision, publishes the
page when it is not published yet and records a revision. A published page without a pending revision, or a page with a planned publication, is refused with
a `ValidationException`. Images used in a pending revision count as in use (see [Media](media.md)).

`Revision::author()` belongs to `cms.user_model`, or to `auth.providers.users.model` when that is `null`.
