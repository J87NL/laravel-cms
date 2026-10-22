# Rendering

Public pages are rendered on the server with Blade. They set no cookies, need no JavaScript and work without the
`web` middleware group.

## Routes

The package registers these routes from its service provider, unless `cms.routes.enabled` is `false`:

| Method | URI | Name | Middleware |
|---|---|---|---|
| `GET` | `/cms/preview/{page}` | `cms.preview` | `signed` |
| `GET` | `/cms/preview/{page}/pending` | `cms.preview.pending` | `signed` |
| `POST` | `/cms/contact/{block}` | `cms.contact` | `throttle:30,1` |
| `GET` | `/sitemap.xml` | `cms.sitemap` | page cache |
| `GET` | `/robots.txt` | `cms.robots` | page cache |
| `GET` | `/llms.txt` | `cms.llms` | page cache |
| `GET` | `/` | `cms.home` (`cms.routes.home_name`) | page cache |
| `GET` | fallback (any other path) | `cms.page` | page cache |

Every route also runs the middleware in `cms.routes.middleware` and
`J87NL\Cms\Http\Middleware\AddSecurityHeaders`. The page cache is
`J87NL\Cms\Http\Middleware\CachePublicResponses`, see [Page cache](page-cache.md).

The page route is a fallback route. Laravel matches fallback routes last, so every route of your application wins
over a CMS page. The package also refuses a page path that matches an application route, see
[Pages](pages.md#slugs-and-paths).

The contact form posts to `cms.contact`. With `cms.routes.enabled` set to `false`, that route does not exist and a
page with a contact form block cannot render.

### Middleware

`cms.routes.middleware` is empty by default, so the routes have no session, no cookies and no CSRF check. The
contact form protects itself without CSRF, see [Contact form](contact-form.md). Do not add the `web` group:

- it starts a session and sets a session cookie, and the [page cache](page-cache.md) neither stores a response that
  sets cookies nor serves a request that has a session cookie, so nothing would be cached;
- the form renders no CSRF token, so its posts would fail the CSRF check.

## Request flow

For a `GET` to the fallback route, `J87NL\Cms\Http\Controllers\PublicPageController`:

1. lowercases the path and looks up a published page with that path;
2. when there is none, or the page is not public (an ancestor is not published), it looks for a
   [redirect](redirects.md) from that path, and otherwise answers with the not-found page (`404`);
3. redirects a request for the home page's own path to `/` (`301`);
4. redirects a path with capitals to the lowercase path (`301`), keeping the query string;
5. renders the page.

For `/` it renders the published home page. Without one, it renders `cms.fallback_home_view` when that view exists,
and the not-found page otherwise.

## The layout

Every page view extends the layout in `cms.layout` and fills three sections:

| Section | Content |
|---|---|
| `title` | The page title, see [Site settings](site-settings.md#title-pattern) |
| `head` | Meta tags, Open Graph and Twitter tags and JSON-LD, see [SEO tags and JSON-LD](seo.md) |
| `content` | An `article` with the page title as `h1` and the blocks |

Your layout must `@yield('title')`, `@yield('head')` and `@yield('content')`. The default layout,
`cms::layouts.default`, is a bare HTML document with the `main` and `footer` menus.

The [Content-Security-Policy](security.md#security-headers) only allows scripts, styles and fonts from your own
domain. Load assets from your own domain, or extend the policy with `cms.security_headers.csp`.

## Views

| View | Purpose |
|---|---|
| `cms::page` | A page: extends the layout, renders `<x-cms::seo>` in `head` and `<x-cms::blocks>` in `content` |
| `cms::not-found` | The not-found page, with `noindex` |
| `cms::layouts.default` | The default layout |
| `cms::blocks.{name}` | One view per built-in block type |
| `cms::blocks.partials.link-tile` | The content of one link tile: its image or its name |
| `cms::components.*` | The views of the Blade components |
| `cms::menu.items` | A menu level, included recursively |
| `cms::mail.contact-form` | The plain text contact form mail |

The block views use BEM-style classes such as `cms-block`, `cms-block--rich-text`, `cms-prose`, `cms-gallery`,
`cms-link-tiles__tile` and `cms-cta__button`. The package ships no CSS: style these classes in your site stylesheet.

### Customizing views

Publish the views and edit the copies:

```bash
php artisan vendor:publish --tag=cms-views
```

Laravel uses `resources/views/vendor/cms/{view}.blade.php` over the package's view. Only keep the files you change.
After a deploy that changes a view, run `php artisan cms:flush-page-cache`, see [Page cache](page-cache.md).

## Translations

All public texts use the `cms` translation namespace. The package ships English (`en`) and Dutch (`nl`) strings for:

| File | Holds |
|---|---|
| `blocks.php` | Block labels, field labels and the contact form texts |
| `menus.php` | Admin labels of the menu keys |
| `pages.php` | Page status labels |
| `permissions.php` | Permission labels |
| `public.php` | Menu `aria-label`s, the link tiles label, the not-found page and the `llms.txt` heading |
| `redirects.php` | Redirect source labels and CSV import errors |
| `settings.php` | Entity type labels |
| `validation.php` | Validation messages of the actions |

Override a string in `lang/vendor/cms/{locale}/{file}.php` of your app.

## Rendering blocks yourself

`J87NL\Cms\Rendering\BlockRenderer::render(Page $page)` returns the visible blocks of a page as a list of
`HtmlString`. It reads them through `Page::visibleBlocks()`, so a page whose `blocks` relation is loaded (as in the
[pending revision preview](previews.md#previewing-a-pending-revision)) renders those blocks instead of the stored ones. Blocks of an unknown or disabled type are skipped. `J87NL\Cms\Rendering\PageResponse::make(Page $page,
bool $preview = false)` returns the full page response that the controllers use.
