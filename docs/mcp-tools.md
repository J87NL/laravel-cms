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
| `menu_item_save` | no | yes | `cms.menus` |
| `redirect_save` | no | no | `cms.redirects` |
| `redirect_delete` | no | yes | `cms.redirects` |

`menu_item_save` is marked destructive, like `block_remove` and `redirect_delete`, because menu changes take effect on
the live website immediately.

### Read tools

| Tool | Arguments | Returns |
|---|---|---|
| `pages_list` | none | The page tree with `id`, `title`, `path`, `url`, `status`, `is_home`, `is_public`, `writable` and `children` |
| `page_get` | `page_id` | One page with all its fields, its schedule and its blocks (`id`, `type`, `position`, `is_visible`, `data`) |
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
| `block_update` | `block_id`, `data` (both required). Pass every field; the type cannot change |
| `block_remove` | `block_id` |
| `blocks_reorder` | `page_id`, `block_ids` (every block id of the page, once, in the new order) |
| `menu_item_save` | `menu` (required), `item_id`, `page_id` or `url`, `label`, `parent_id`. On an update, omitted fields keep their value |
| `redirect_save` | `redirect_id`, `from_path` and `to` (required), `status_code` (`301` or `302`). A new redirect defaults to `301`; on an update, an omitted status code keeps its current value |
| `redirect_delete` | `redirect_id` |

The descriptions of `block_add` and `block_update` list the registered block types with their translated label and
their data fields, built from each type's `label()` and `rules()`, so custom block types appear there too.

`blocks_reorder` with the current order changes nothing and records no revision.

## Rules the tools follow

- No tool publishes, unpublishes, schedules, makes a home page, deletes pages or uploads media. Publishing stays in the
  CMS admin, see [Publication](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/publication.md) in the
  laravel-vue-cms docs.
- `page_update` and the block tools only change draft or unpublished pages without a planned publication
  (`publish_at`). A published or scheduled page is refused, also for a user who may publish. `pages_list` and
  `page_get` report this as `writable`.
- When the app defines the `cms.pages.write` gate, the page and block tools check it with the page, see
  [Permissions](permissions.md).
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
