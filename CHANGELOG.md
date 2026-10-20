# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.1]

### Added

- `cms:flush-page-cache` drops every cached public page. Run it after a deploy, because a changed layout or view does not invalidate the cache by itself.

## [0.2.0]

### Added

- MCP tools on `laravel/mcp` for the J87 MCP hub: `pages_list`, `page_get`, `media_list`, `menu_items_list` and `redirects_list` to read, and `page_draft_create`, `page_update`, `block_add`, `block_update`, `block_remove`, `blocks_reorder`, `menu_item_save`, `redirect_save` and `redirect_delete` to write. Page and block writes only act on draft or unpublished pages without a planned publication, no tool publishes, every tool checks the acting user's CMS permission, and only the hub grant level `full` runs tools that are not read-only. `block_remove`, `menu_item_save` and `redirect_delete` are marked destructive.
- `Cms::mcpTools()` to register the tools on the app's MCP server, and `Cms::mcpInstructions()` for clients that connect without the hub.
- The `SaveMenuItem` and `ReorderBlocks` actions, and the `PageRules` and `RedirectRules` validation rules shared with the admin.

### Changed

- `laravel/mcp` `^0.7` is a required dependency.

## [0.1.0]

First release, extracted from the j87.nl app.

### Added

- Pages as a tree with draft, published and unpublished states, a single public home page and revisions with restore.
- A block registry with rich text, text with image, image, gallery, call to action, embed, dates and contact form blocks.
- Rich text sanitized with `symfony/html-sanitizer` on save and on render.
- A media library on `spatie/laravel-medialibrary` with required alt text, a focal point, queued WebP conversions and stripped metadata.
- Main and footer menus.
- Cookie-free public Blade rendering with a catch-all route.
- Redirects created on every path change, chains collapsed to one hop, manual redirects and CSV import.
- Site settings for the site name, title pattern, default description, the person or organization behind the site, `robots.txt` and `llms.txt` texts, the privacy page and the contact recipient.
- SEO tags, Open Graph and Twitter tags and a JSON-LD graph.
- `sitemap.xml` with a `SitemapContributor` contract, `robots.txt` and `llms.txt`.
- Planned publishing and unpublishing through `cms:publish-scheduled`.
- Signed previews of drafts.
- A full-page cache for anonymous visitors.
- A Content-Security-Policy on public routes, with HSTS, nosniff, Referrer-Policy and Permissions-Policy switchable per header.

### Security

- Require `symfony/html-sanitizer` `^7.4.13|^8.0.13` for CVE-2026-45064, CVE-2026-45066, CVE-2026-45753, CVE-2026-48760 and CVE-2026-48761.

[Unreleased]: https://github.com/J87NL/laravel-cms/compare/v0.2.0...HEAD
[0.2.1]: https://github.com/J87NL/laravel-cms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/J87NL/laravel-cms/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/J87NL/laravel-cms/releases/tag/v0.1.0
