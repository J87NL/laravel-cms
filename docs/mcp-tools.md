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

The package ships 27 tools. `laravel/mcp` lists 15 tools per `tools/list` page by default, so set
`public int $defaultPaginationLength = 50;` on the server when the client or hub does not follow `nextCursor`.

## The tools

| Tool | Read-only | Destructive | Permission |
|---|---|---|---|
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
destructive too, like `media_delete`, which only deletes images nobody uses but cannot be undone.

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
- `pending_revision.changes` in `page_get` (and in the other tools that return the whole page) summarises what
  publishing would change compared with the live page, from
  [`DiffPendingRevision`](revisions.md#comparing-a-pending-revision-with-the-live-page): `fields` (each with `field`,
  `live` and `pending`), `blocks` (only the blocks that change or move, each with `id`, `type`, `change` as `added`,
  `removed` or `changed`, `moved`, `changed_keys`, `markup_only_keys`, `link_changes` and `visibility_changed`) and
  `reordered`. `markup_only_keys` are the changed rich text values whose text stays the same, so only the markup
  (formatting or a link) changes; `link_changes` lists, per value, the `href`, `src` and `srcset` values that publishing
  `added` and `removed`. Check both: a changed link target does not show in the text.
- Blocks keep their id in the pending revision. A block added there gets a negative id (`-1`, `-2`, …) until it is
  published; an id is never handed out twice. Pass `page_id` to `block_update` and `block_remove` for a block that is
  not on the live page.
- A block added directly on the live page after the pending revision started is taken into the pending revision (and
  its base) unchanged, so `page_get` shows it under `pending_revision` and `block_update`, `block_remove` and
  `blocks_reorder` can change it there. Publishing leaves it as it is unless the pending revision changed it, and
  places it where `page_get` shows it.
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
| `media_upload` | `content_base64` with `file_name`, or `source_url` (one of the two, not both); `alt` (required); `sha256`, `caption`, `focal_x`, `focal_y` (0 to 100, default 50). Returns the new image under `media`, in the shape of `media_list` |
| `media_upload_start` | `file_name`, `size` (bytes), `sha256` of the whole file, `alt` (all required), `caption`, `focal_x`, `focal_y`. Returns `upload_id`, `chunk_bytes`, `chunk_count` and `expires_at` |
| `media_upload_chunk` | `upload_id`, `index` (0-based), `content_base64` and the chunk's `sha256` (all required) |
| `media_upload_finish` | `upload_id`. Returns the new image under `media`, like `media_upload` |
| `media_delete` | `media_ids` (1 to 25 distinct ids). Returns `results`: per id its `status` (`deleted`, `in_use`, `not_found` or `failed`), and for deleted and in-use images `alt` and `file_name`, plus `usage_count` when in use |

### Uploading images

`media_upload` adds an image to the media library, so its id can go into block data (`media_id`, `media_ids`) or
`og_image_id` without the admin:

1. `content_base64` holds the file bytes, base64 encoded. A `data:…;base64,` prefix and whitespace are stripped;
   anything else that is not valid base64 is refused.
2. With `sha256` (64 hexadecimal characters, the SHA-256 of the file before encoding), the decoded bytes are checked
   against it first. A mismatch is refused as a damaged transfer, with the number of bytes that arrived, so the client
   can tell a miscopied base64 string from a bad image. Without `sha256` nothing changes. Clients should send it:
   long base64 strings, above all with repetitive runs such as palette PNGs, are sometimes miscopied by a language
   model, and without the checksum that shows up as an unreadable image.
3. The size is checked against `cms.media.max_upload_kilobytes` before the bytes are decoded and again after, so an
   oversized file never reaches the disk.
4. The bytes go to a temporary file whose type is read with `finfo`. Only `MediaAsset::ACCEPTED_MIME_TYPES` (JPEG,
   PNG, WebP and GIF) pass, whatever `file_name` says; SVG and HTML are refused. The stored file name is the slug of
   `file_name` with the extension of the real type, so JPEG content named `foto.png` is stored as `foto.jpg`.
5. [`UploadMedia`](media.md#uploading) does the rest, exactly as for an admin upload: the attribute rules of
   `MediaRules`, re-encoding with `ImageCleaner` (no metadata, no animated GIF, the pixel limit) and the queued WebP
   conversions. Until the queue has run, `url` points to the original.

The temporary file is removed after both a success and a refusal. Refusals come back as an error result with a
sentence. The upload tools are not marked destructive, because a new image changes nothing that visitors see until a
block uses it.

#### Chunked uploads

For a large file, or one that keeps arriving damaged, a client uploads it in chunks, so a miscopied chunk is resent on
its own instead of the whole file:

1. `media_upload_start` takes the file name, its `size` in bytes, the `sha256` of the whole file and the image fields.
   It checks the size against `cms.media.max_upload_kilobytes` and the fields against `MediaRules`, and returns an
   `upload_id` and the number of chunks. Every chunk holds `MediaUploadSession::CHUNK_BYTES` (2048) bytes of the
   file, the last one the rest.
2. `media_upload_chunk` takes one chunk as base64 with its own `sha256`. A chunk whose checksum or size does not match
   is refused and not stored; the other chunks stay. Sending an index again replaces it.
3. `media_upload_finish` lists the missing indexes when chunks are missing. Otherwise it joins the chunks, checks the
   size and `sha256` of the whole file and runs steps 4 and 5 above. A checksum mismatch keeps the upload, so wrong
   chunks can be sent again; a refused file type or image ends it.

An upload is a `J87NL\Cms\Models\MediaUploadSession` row (`cms_media_upload_sessions`) with its chunks in a directory
of its own on the disk `cms.media.chunked_uploads.disk`. It belongs to the user who started it: for anyone else, and
after it expired (`cms.media.chunked_uploads.ttl_minutes`, default 60, counted from the start and again from every
stored chunk), it is not found. A user can have at most
`cms.media.chunked_uploads.max_open_per_user` (default 5) open uploads. The command `cms:prune-media-uploads`, which
the package schedules every hour (also when `cms.scheduling.enabled` is `false`), deletes expired uploads with their
chunks and chunk directories that have no upload. Disk use is only bounded while the scheduler runs. A chunk that
arrives while its upload is being finished is not kept. When `cms.media.max_upload_kilobytes` is `0`, MCP uploads
still stop at `ImageUploader::HARD_MAX_BYTES` (64 MB).

#### Deleting images

`media_delete` deletes images through [`DeleteMedia`](media.md#deleting), one id at a time, so an image in use or a
missing id does not stop the others. `DeleteMedia` refuses an image that `MediaUsage` counts as used (blocks, page
`og_image_id`, pending revisions and the site settings); the tool reports it as `in_use` with its usage count and
leaves it and its files alone. Any other error for one id is reported (`report()`) and comes back as `failed` for that
id, after which the other ids are still handled, so the client always learns which images were deleted. A deleted image loses its media rows and its files, which are removed after the delete
commits. Deleting cannot be undone, so the tool is marked destructive and its description asks the assistant to
confirm with the user first; `media_list` shows `in_use` per image.

#### Downloading from source_url

Instead of `content_base64`, `media_upload` takes `source_url`, a public `https://` address of an image that is already
online. It does not help for files in an assistant's own sandbox, which have no public address.

- Only `https` on port 443, without a user name or password.
- The host must resolve, and every address it resolves to must be public: private, loopback, link-local, shared
  (100.64.0.0/10), reserved, documentation, benchmarking and multicast IPv4 ranges are refused, and IPv6 is only
  allowed in global unicast (`2000::/3`) outside its translation and documentation ranges. The connection is pinned to
  the address that was checked (`CURLOPT_RESOLVE`), so the name cannot be resolved again to another address.
- The request always goes through Guzzle's curl handler and never through a proxy, also when `HTTPS_PROXY` or
  `HTTP_PROXY` is set: a proxy would resolve the name itself and undo the pinning. A server that can only reach the
  internet through a proxy cannot use `source_url`.
- Redirects are followed by hand, at most 3, and every new address is checked the same way.
- The whole download, redirects included, times out after 10 seconds (resolving the host names is not part of that
  time; a failed lookup is not retried) and stops as soon as it passes
  `cms.media.max_upload_kilobytes`, also when the server announces no `Content-Length`: the progress callback stops
  the transfer on Guzzle 7 and 8 alike, and the body goes straight to a temporary file through a stream filter that
  drops every byte past the limit. The image is asked for uncompressed (`Accept-Encoding: identity`) and never
  decoded, so the counted bytes are the stored bytes; an answer that is compressed anyway is refused.
- `sha256` is checked against the downloaded file when given. The file name defaults to the last part of the address.
- The file then goes through steps 4 and 5 above.

The host names are resolved by `J87NL\Cms\Media\Remote\HostResolver`, bound to `DnsHostResolver` unless the app
binds its own.

The descriptions of `block_add` and `block_update` list the registered block types with their translated label and
their data fields, built from each type's `label()` and `rules()`, so custom block types appear there too.

`blocks_reorder` with the current order changes nothing and records no revision.

## Rules the tools follow

- No tool schedules, makes a home page, deletes pages or replaces the file of an image; those stay in the CMS admin.
  The upload tools only add new images and `media_delete` only deletes images that are not in use.
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
