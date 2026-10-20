# Redirects

Redirects are `J87NL\Cms\Models\Redirect` records in `cms_redirects`. Each has a `from_path` (without leading slash),
a target `to`, a `status_code` (`301` or `302`), a hit counter and a `source`:

| Source | Value | Created by |
|---|---|---|
| `RedirectSource::Auto` | `auto` | A path change of a public page |
| `RedirectSource::Manual` | `manual` | An editor, or the `redirect_save` MCP tool |
| `RedirectSource::Import` | `import` | A CSV import |

Editors add, edit and import redirects in the admin of `j87nl/laravel-vue-cms`, see
[Redirects](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/redirects.md) in its docs.

## How a redirect applies

A redirect only applies when no public page has the requested path: a published page always wins. When a redirect
matches, the package increments its `hits`, sets `last_hit_at` and answers with the redirect's status code. The query
string of the request is appended to the target, unless the target already has a query string or a fragment.

A redirect from the path of a page that is not public (a draft, or a page below an unpublished parent) still applies.

## Automatic redirects

When the path of a public page changes, because its slug or parent changed, the package creates a `301` from the old
path to the new one. This happens for the page and for every public subpage.

No redirect is created when:

- the page was not public before the change, so a draft never leaks its URL;
- the page is not public after the change, so its old URL becomes a plain not-found.

When a public page moves to a path that a redirect starts from, that redirect is deleted. Moving a page back to an
old path therefore removes the redirect from that path.

## Chains and loops

Every save resolves the target to its final destination, so a visitor gets at most one hop:

- a new redirect to a URL that already redirects points straight at the final target;
- existing redirects that point at the new redirect's `from_path` are updated to its target;
- a redirect that would end up pointing at itself is deleted;
- a redirect that would form a loop is refused.

## Manual redirects

```php
use J87NL\Cms\Actions\SaveRedirect;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Models\Redirect;

app(SaveRedirect::class)->handle(new Redirect, '/old-page', '/new-page', 301, RedirectSource::Manual);
```

`SaveRedirect::handle(Redirect $redirect, string $from, string $to, int $statusCode, RedirectSource $source): Redirect`
creates or updates a redirect. A status code other than `301` or `302` is stored as `301`.

The `from` value is normalized: a full URL on this site (the host of `app.url`, with or without `www.`) becomes its
path, the query string and fragment are dropped, the path is URL-decoded, lowercased and trimmed of slashes.

The `to` value is stored as:

- a path starting with `/` when it is a path or a full URL on this site;
- the full URL when it is an `http://` or `https://` URL on another host.

It throws a `ValidationException` when the `from` path is the home page (`/`), belongs to an existing page, already
has a redirect, or when the redirect would loop.

`J87NL\Cms\Validation\RedirectRules::fields()` holds the request rules shared with the admin and the MCP tools:
`from_path` at most 255 characters, `to` at most 2,048 characters starting with `/`, `http://` or `https://`, and
`status_code` `301` or `302`.

## CSV import

```php
use J87NL\Cms\Actions\ImportRedirects;

$result = app(ImportRedirects::class)->handle($request->file('csv')->getRealPath());

$result->imported; // int
$result->errors;   // array<int, string>, keyed by line number
```

`ImportRedirects::handle(string $file): ImportResult` reads a CSV file with the columns old URL, new URL
and an optional status code (default `301`):

```csv
from;to;status
/old-page;/new-page;301
/blog/2019/post;https://example.org/post;302
```

- The delimiter is `;` or `,`, whichever occurs more on the first line.
- A first line whose first column is `from`, `from_path` or `van` is treated as a header and skipped.
- A UTF-8 byte order mark is removed.
- An existing redirect from the same path is updated.
- Every row goes through `SaveRedirect` with the source `import`.

The import is all or nothing. When any line fails, nothing is imported, `imported` is `0` and `errors` holds a
message for every bad line.
