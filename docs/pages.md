# Pages

A page is a `J87NL\Cms\Models\Page` in the `cms_pages` table. Pages form a tree: every page has an optional parent,
and its public URL follows from the slugs of its ancestors.

This page describes the model and its rules. Editors manage pages in the admin of `j87nl/laravel-vue-cms`, see
[Pages](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/pages.md) in its docs.

## Fields

| Field | Purpose |
|---|---|
| `title` | The page title, shown as the `h1` and used for the meta title |
| `slug` | The last part of the URL. Derived from the title when left empty |
| `parent_id` | The parent page, or `null` for a top-level page |
| `menu_title` | A shorter title for menus and breadcrumbs |
| `meta_title` | A title for search engines. Replaces the generated title as is |
| `meta_description` | A description for search engines |
| `og_image_id` | A media asset used as share image |
| `canonical_url` | A canonical URL, for a page that duplicates another one |
| `noindex` | Keep the page out of search engines, the sitemap and `llms.txt` |
| `schema_type` | The JSON-LD type of the page, `WebPage` when empty |

These are the fields in `Page::CONTENT_ATTRIBUTES`, the only ones `SavePage` fills. The other columns are managed by
the package: `path`, `status`, `is_home`, `sort_order`, `publish_at`, `unpublish_at` and `schedule_error`.

## Creating and updating

```php
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Models\Page;

$page = app(SavePage::class)->handle(new Page, [
    'title' => 'Team',
    'parent_id' => $about->id,
], userId: auth()->id());
```

`SavePage::handle(Page $page, array $attributes, ?int $userId): Page` fills the content fields, saves the page and
records a [revision](revisions.md). A new page starts as a draft and is placed last among its siblings.

`J87NL\Cms\Validation\PageRules::fields()` holds the validation rules for these fields, shared by the admin and the
MCP tools. Use it in your own form requests:

```php
use J87NL\Cms\Validation\PageRules;

public function rules(): array
{
    return [
        'title' => ['required', ...PageRules::for('title')],
        'slug' => PageRules::for('slug'),
        'meta_description' => PageRules::for('meta_description'),
    ];
}
```

## Slugs and paths

The slug is passed through `Str::slug()`. An empty slug is taken from the title, and when that is empty too the
slug becomes `page`. The `path` column is computed on every save: the parent's path and the slug, joined with `/`.
`Page::url()` returns `/` for the home page and `/{path}` for any other page.

`SavePage` and `MovePage` refuse a placement with a `ValidationException` on `slug` or `parent_id` when:

- another page already has the path;
- the path, or the new path of a subpage, is longer than 255 characters;
- the path, or the new path of a subpage, matches a route of the application itself;
- the parent does not exist, or is the page itself or one of its subpages.

When the slug or parent of a page changes, the paths of all its subpages are recomputed, and redirects are created
for the old URLs. See [Redirects](redirects.md#automatic-redirects).

## Moving pages

```php
use J87NL\Cms\Actions\MovePage;

app(MovePage::class)->handle($page, $target, MovePage::AFTER, userId: auth()->id());
```

The position is `MovePage::BEFORE` or `MovePage::AFTER` the target (as its sibling), or `MovePage::INSIDE` the
target (as its last child). The siblings are renumbered and a revision is recorded.

## Statuses

A page has one of the `J87NL\Cms\Enums\PageStatus` values:

| Status | Value | Public |
|---|---|---|
| `PageStatus::Draft` | `draft` | No |
| `PageStatus::Published` | `published` | Yes, when all its ancestors are published too |
| `PageStatus::Unpublished` | `unpublished` | No |

A published page below a draft or unpublished ancestor is not public. It is left out of the website, the menus,
the sitemap and `llms.txt`. `J87NL\Cms\Pages\PageVisibility::isPublic(Page $page)` applies this rule.

Change the status with `ChangePageStatus`:

```php
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Enums\PageStatus;

app(ChangePageStatus::class)->handle($page, PageStatus::Published, userId: auth()->id());
```

It records a revision. To plan a status change, see [Scheduling](scheduling.md).

## The home page

One page can be the home page (`is_home`). It is served at `/`, and a request for its own path redirects to `/`
with a `301`.

- When a page is published while there is no home page, and that page is public, it becomes the home page.
- `MakeHomePage::handle(Page $page)` makes another page the home page. Only a public page qualifies.
- The home page, and every page above it, cannot be unpublished.
- The home page, and every page above it, can only be moved below a public page.
- The home page cannot be deleted.

These changes run under a cache lock (`cms:home-page`). When another change holds the lock for more than five
seconds, the action throws a `ValidationException` that asks to try again.

While no home page is published, `/` shows the view in `cms.fallback_home_view`, or the not-found page when that is
`null`.

## Deleting pages

```php
use J87NL\Cms\Actions\DeletePage;

app(DeletePage::class)->handle($page);
```

The home page and pages with subpages cannot be deleted. `DeletePage::blockedReason(Page $page)` returns the reason
as a string, or `null` when the page can be deleted, so an admin can disable the button beforehand. Deleting a page
also deletes its blocks, its revisions and the menu items that link to it.
