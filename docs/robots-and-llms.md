# robots.txt and llms.txt

The package serves both files as `text/plain; charset=UTF-8`, through the [page cache](page-cache.md). Delete
`public/robots.txt` from your app, because the web server would serve that file first.

## robots.txt

Route `cms.robots`. The file is built from:

1. `User-agent: *` with a `Disallow:` line for every path in `cms.robots.disallow` (default `/admin`);
2. the non-empty lines of the site setting `robots_extra`, after a blank line;
3. a `Sitemap:` line with the full URL of `/sitemap.xml`.

```text
User-agent: *
Disallow: /admin

User-agent: GPTBot
Disallow: /

Sitemap: https://example.org/sitemap.xml
```

The `robots_extra` lines are trimmed and empty lines are dropped; otherwise they are added as typed, so editors can
add rules for specific crawlers.

## llms.txt

Route `cms.llms`. The file follows the `llms.txt` format: a heading with the site name, an optional quote with an
introduction, and a list of pages.

```text
# J87

> Developer in Heenvliet.

## Pages

- [Welcome](https://example.org/): The front door
- [About](https://example.org/about): Who I am
```

- The introduction is the site setting `llms_intro`, or the `default_meta_description` when that is empty.
- The pages are the same as in the [sitemap](sitemap.md#pages-in-the-sitemap). Pages added by sitemap contributors
  are not listed.
- Each page has its title and full URL, followed by its [description](seo.md#description) when there is one.
- The heading "Pages" is the translation `cms::public.llms.pages`.

Square brackets in titles and descriptions are replaced by parentheses, and line breaks by spaces, so every entry
stays on one line.
