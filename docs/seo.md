# SEO tags and JSON-LD

Every public page gets its meta tags, Open Graph and Twitter tags and a JSON-LD graph from
`J87NL\Cms\Seo\PageSeo`. The `<x-cms::seo>` component renders them in the `head` section of your layout.

## Tags

| Tag | Value |
|---|---|
| `<title>` | The meta title, or the page title through the [title pattern](site-settings.md#title-pattern) |
| `meta description` | See [Description](#description). Left out when empty |
| `meta robots` | `noindex, follow` for a page with `noindex`, `noindex, nofollow` in a preview. Left out otherwise |
| `link rel="canonical"` | The page's `canonical_url`, or its own full URL |
| `og:type` | `website` |
| `og:site_name` | The site name |
| `og:locale` | From `app.locale`, for example `nl_NL` |
| `og:title`, `twitter:title` | The title |
| `og:description`, `twitter:description` | The description, when not empty |
| `og:url` | The canonical URL |
| `og:image` | The largest version (WebP conversion, or the original) of the page's `og_image_id`, or of the site's `og_image_id` |
| `og:image:width`, `og:image:height`, `og:image:alt` | Of that image |
| `twitter:card` | `summary_large_image` with an image, `summary` without |

`og:locale` uses a locale with a region as is (`en_GB`). A bare language is mapped to a common region (`nl` becomes
`nl_NL`, `de` becomes `de_DE`); unknown languages become `en_US`.

## Description

The description is the first of:

1. the page's `meta_description`;
2. the plain text of the first visible block that has text, cut at 160 characters on a word boundary with `…`;
3. the site's `default_meta_description`.

Block text comes from `BlockType::toPlainText()`. Image and gallery blocks have no text.

## JSON-LD

The JSON-LD script holds one `@graph` with these nodes:

| Node | Content |
|---|---|
| `Person` or `Organization` | The entity from the site settings: name, URL, job title (person only), email, photo (`image`) or logo, and `sameAs` profile URLs. `@id` is `{site url}/#entity` |
| `WebSite` | URL, site name, language and the entity as publisher. `@id` is `{site url}/#website` |
| The page | Type from `schema_type`, or `WebPage`. URL, name (meta title or title), description, language, `isPartOf` the website, `dateModified`, and on the home page `about` the entity |
| `BreadcrumbList` | For a page below another page: the home page, then every published page on the path |
| Block nodes | Nodes from visible blocks whose type implements `HasJsonLd`, such as the `Event` nodes of a dates block |

The language is `app.locale` with `_` replaced by `-`. The JSON is encoded with `JSON_HEX_TAG` and `JSON_HEX_AMP`, so
content cannot close the script tag.

## Using it in code

```php
use J87NL\Cms\Seo\PageSeo;

$seo = app(PageSeo::class)->forPage($page);

$seo->title;       // string
$seo->description; // string
$seo->canonical;   // string
$seo->robots;      // ?string
$seo->image;       // ?array{url: string, width: int, height: int, alt: string}
$seo->jsonLd();    // the JSON-LD document as a string
```

`forPage(Page $page, bool $preview = false): SeoData` builds everything. `PageSeo` also has the parts on their own:

- `title(Page $page, ?SiteSettings $settings = null): string`
- `description(Page $page, ?array $blocks = null, ?SiteSettings $settings = null): string`, where `$blocks` is a
  list of `Block` models to take the text from (the page's visible blocks when `null`)
- `canonical(Page $page): string`

## Pages left out of search

A page with `noindex` gets `<meta name="robots" content="noindex, follow">` and is left out of the
[sitemap](sitemap.md) and [`llms.txt`](robots-and-llms.md). A page whose `canonical_url` points to another URL
is left out of the sitemap and `llms.txt` too. The not-found page always has
`<meta name="robots" content="noindex">`.
