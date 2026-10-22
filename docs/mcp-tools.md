# MCP tools

The package ships CMS tools for [`laravel/mcp`](https://github.com/laravel/mcp), so assistants can read and edit the
site's content. The tool names are short and unprefixed, for example `pages_list`.

## Registering the tools

Add the tools to the app's own MCP server:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

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

`Cms::mcpTools()` returns the tool class names (`J87NL\Cms\Mcp\CmsTools::TOOLS`). `Cms::mcpInstructions()` returns a
short guide to the tools for the server's `instructions`. Every tool description is also complete on its own, for
clients that do not read the server instructions.

`boot()` runs before every request is handled. Keep the server's own instructions in the `$instructions` property:
an `#[Instructions]` attribute takes precedence over the property and would drop the CMS guidance.

The package ships 22 tools. `laravel/mcp` lists 15 tools per `tools/list` page by default, so set
`public int $defaultPaginationLength = 50;` on the server when the client or hub does not follow `nextCursor`.

## The tools

| Tool | Read-only | Destructive | Permission |
|---|---|---|---|
| `pages_list` | yes | no | `cms.pages.edit` |
| `page_get` | yes | no | `cms.pages.edit` |
| `media_list` | yes | no | `cms.media` |
| `menu_items_list` | yes | no | `cms.menus` |
| `redirects_list` | yes | no | `cms.redirects` |
| `page_draft_create` | no | no | `cms.pages.edit` |
| `page_update` | no | no | `cms.pages.edit` |
| `block_add` | no | no | `cms.pages.edit` |
| `block_update` | no | no | `cms.pages.edit` |
| `block_remove` | no | yes | `cms.pages.edit` |
| `blocks_reorder` | no | no | `cms.pages.edit` |
| `page_update_live` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `block_add_live` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `block_update_live` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `block_remove_live` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `blocks_reorder_live` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `page_publish` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `page_unpublish` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `revision_discard` | no | yes | `cms.pages.edit` and `cms.pages.publish` |
| `menu_item_save` | no | yes | `cms.menus` |
| `redirect_save` | no | no | `cms.redirects` |
| `redirect_delete` | no | yes | `cms.redirects` |

Every tool that changes what visitors see is marked destructive: the live tools, `page_publish`, `page_unpublish`,
`menu_item_save` and `redirect_delete`. A hub that disables destructive tools until someone enables them (as the J87
MCP hub does) therefore keeps the draft tools available and the live tools off. `block_remove` and `revision_discard`
change nothing for visitors when they act on a pending revision, but they throw content away, so they are marked
destructive too.

## Draft and live mode

The page and block write tools come in two modes. The mode is part of the tool name, not an argument, because MCP
annotations (and the hub's destructive switch) apply per tool:

| | Draft tools (`page_update`, `block_add`, …) | Live tools (`page_update_live`, `block_add_live`, …) |
|---|---|---|
| Draft or unpublished page | Changes the page directly | Changes the page directly |
| Published page | Stores the change in the page's pending revision; the live page stays as it is | Changes the live page at once, records a revision and flushes the page cache |
| Page with a planned publication (`publish_at`) | Refused | Refused |
| Permission | `cms.pages.edit` | `cms.pages.edit` and `cms.pages.publish` |

### Pending revisions

A published page has at most one pending revision: a revision row with `is_pending` set, holding the page fields and
the blocks as they will be after publishing, and the live page as it was when the revision started (its base). The
first draft write copies the live page into it; every later draft write changes that copy. The public page, the page
cache and the revision history stay as they are. A draft write that leaves the copy equal to its base removes the
pending revision again, so `pending_revision` is `null` in that response.

- `page_get` returns the live page and, under `pending_revision`, the pending page fields and blocks. `pages_list` and
  `page_get` report `has_pending_revision`.
- Blocks keep their id in the pending revision. A block added there gets a negative id (`-1`, `-2`, …) until it is
  published; an id is never handed out twice. Pass `page_id` to `block_update` and `block_remove` for a block that is
  not on the live page.
- A block added directly on the live page after the pending revision started is taken into the pending revision (and
  its base) unchanged, so `page_get` shows it under `pending_revision` and `block_update`, `block_remove` and
  `blocks_reorder` can change it there. Publishing leaves it as it is unless the pending revision changed it.
- `page_publish` applies only what the pending revision changed compared with its base, in one transaction: changed
  page fields and blocks are written (a block keeps its id), new blocks are created and removed blocks are deleted.
  Fields and blocks the pending revision left alone keep their live value, so changes made directly on the live page in
  the meantime (by the live tools or the admin) stay, unless the pending revision changed the same field or block: then
  the pending revision wins (a block it changed but that was removed live comes back, a block it removed is removed even
  when it changed live). When the pending revision changed the order of the blocks it shares with its base, its blocks
  come first in that order, followed by blocks added live in the meantime. Otherwise the live order stays, and a block
  added in the pending revision goes in front of the block that follows it there (right after a block that was added
  with it, at the top when nothing comes before it, or at the end).
  The pending revision is removed, a normal revision is recorded and the page cache is flushed. The live
  tools say in their answer when the page has a pending revision.
- Taking a page offline (`page_unpublish`, the admin or a planned unpublish) applies its pending revision to the page in
  the same way, so a pending revision only exists on a published page and edits to an offline page go to the page
  directly. A pending revision never blocks taking a page offline: when its new slug or parent cannot be applied (for
  example because the address is taken), the rest is applied, the address stays as it was and a warning is logged.
- `pending_revision.created_by` is the user who started it and `pending_revision.edited_by` the user of the last change.
- `revision_discard` deletes the pending revision; the live page stays as it is.
- Images used in a pending revision count as in use, so they cannot be deleted before it is published or discarded.
- A new slug or parent is checked when the revision is published. A taken address refuses the publication and keeps
  the pending revision.

### Read tools

| Tool | Arguments | Returns |
|---|---|---|
| `pages_list` | none | The page tree with `id`, `title`, `path`, `url`, `status`, `is_home`, `is_public`, `writable`, `has_pending_revision` and `children` |
| `page_get` | `page_id` | One page with all its fields, its schedule, its blocks (`id`, `type`, `position`, `is_visible`, `data`) and `pending_revision` (or `null`) |
| `media_list` | `search`, `page`, `per_page` (default 25, at most 100) | Images, newest first, with `id`, `alt`, `caption`, `url`, `thumbnail_url`, size and usage |
| `menu_items_list` | `menu` (optional) | Per menu key, the items with `id`, `label`, `custom_label`, `page_id`, `url`, `href`, `parent_id`, `position` and `is_visible` |
| `redirects_list` | `from`, `page`, `per_page` (default 50, at most 200) | Redirects sorted by from path, with target, status code, source and hits |

`search` and `from` match anywhere in the text; `%` and `_` are matched literally.

### Write tools

| Tool | Arguments |
|---|---|
| `page_draft_create` | `title` (required), `slug`, `parent_id`, `menu_title`, `meta_title`, `meta_description`, `og_image_id`, `canonical_url`, `noindex`, `schema_type` (one of `cms.schema_types`) |
| `page_update` | `page_id` (required) and any of the fields above; only passed fields change |
| `block_add` | `page_id`, `type`, `data` (all required). The block is added at the end |
| `block_update` | `block_id`, `data` (both required), `page_id` (for a block that only exists in the pending revision). Pass every field; the type cannot change |
| `block_remove` | `block_id`, `page_id` (for a block that only exists in the pending revision) |
| `blocks_reorder` | `page_id`, `block_ids` (every block id of the page, or of its pending revision, once, in the new order) |
| `page_update_live`, `block_add_live`, `block_update_live`, `block_remove_live`, `blocks_reorder_live` | The same as the draft tool without `_live` |
| `page_publish` | `page_id`. Publishes a draft or unpublished page, and applies the pending revision when there is one |
| `page_unpublish` | `page_id`. Takes a published page offline; not the home page or a page that contains it |
| `revision_discard` | `page_id` |
| `menu_item_save` | `menu` (required), `item_id`, `page_id` or `url`, `label`, `parent_id`. On an update, omitted fields keep their value |
| `redirect_save` | `redirect_id`, `from_path` and `to` (required), `status_code` (`301` or `302`). A new redirect defaults to `301`; on an update, an omitted status code keeps its current value |
| `redirect_delete` | `redirect_id` |

The descriptions of `block_add` and `block_update` list the registered block types with their translated label and
their data fields, built from each type's `label()` and `rules()`, so custom block types appear there too.

`blocks_reorder` with the current order changes nothing and records no revision.

## Rules the tools follow

- No tool schedules, makes a home page, deletes pages or uploads or replaces media files; those stay in the CMS admin.
- Pages with a planned publication (`publish_at`) are read-only for every page and block tool, also for a user who may
  publish. `pages_list` and `page_get` report this as `writable`.
- When the app defines the `cms.pages.write` gate, the direct writes check it with the page: every live tool, and the
  draft tools on a draft or unpublished page. Draft writes into a pending revision skip it, because they change nothing
  that visitors see; publishing them checks it. See [Permissions](permissions.md).
- `page_publish`, `page_unpublish` and `revision_discard` need `cms.pages.edit` and `cms.pages.publish`, and check the
  `cms.pages.write` gate with the page. So a proposal on a page that the gate protects waits until someone the gate
  allows publishes it.
- Writes use the same actions and validation rules as the admin (`PageRules`, `RedirectRules`, the block type's
  rules and `SafeLink`), so sanitizing, revisions, redirects and cache invalidation work the same.
- `menu_item_save` only accepts the menu keys in `cms.menus`. It may link to any page, but the item only shows while
  that page and its ancestors are published.

## Access checks

Every tool runs as the authenticated user of the MCP request (`$request->user()`):

- without a user, the tool returns an error result and does nothing;
- without the tool's permission (checked with `Gate::forUser($user)->allows(...)`), the tool returns an error result;
- a tool that is not read-only only runs when the HTTP request has the header `X-J87-Grant-Level: full`. A missing
  header, `read` or any other value only allows the read-only tools.

Refusals and validation failures come back as an MCP error result with a sentence, never as an exception.

## What the app provides

The package only provides the tools. The app provides:

- an MCP server that registers the tools, and a route to it, for example `Mcp::web('/internal/mcp', SiteServer::class)`;
- authentication of that route, so `$request->user()` is the acting user;
- the `X-J87-Grant-Level: full` header on requests that may run write tools;
- gates for the CMS permissions, see [Permissions](permissions.md).
