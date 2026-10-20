# Revisions

Every change to a page or its blocks stores a snapshot of the page in `cms_revisions`, as a
`J87NL\Cms\Models\Revision`. A revision can be restored.

## What is recorded

These actions record a revision after their change:

- `SavePage`, `MovePage` and `ChangePageStatus`;
- `SaveBlock`, `DeleteBlock`, `ToggleBlockVisibility` and `ReorderBlocks`;
- `RestoreRevision`.

`SchedulePage`, `MakeHomePage` and `DeletePage` do not record a revision.

The snapshot holds the page's content fields (`Page::CONTENT_ATTRIBUTES`), its `status` and `is_home`, and every
block with its `type`, `data`, `sort_order` and `is_visible`. The `created_by` column holds the `$userId` passed to
the action, or `null`.

## Pruning

Only the latest `cms.revisions.keep` revisions are kept per page (20 by default, at least 1). Older ones are deleted
when a new one is recorded.

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
`ValidationException`.

## Listing revisions

```php
$page->revisions; // newest first
$revision->author; // the user who made it, or null
```

`Revision::author()` belongs to `cms.user_model`, or to `auth.providers.users.model` when that is `null`.
