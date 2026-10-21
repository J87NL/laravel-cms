# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.2] - 2026-10-21

### Added

- `UpdateMedia` changes the alt text, caption and focal point of an asset with the new `MediaRules`.
- `ReorderMenuItems` and `DeleteMenuItem`, so the admin and apps reorder and delete menu items through actions.
- `cms.schema_types` lists the schema.org types a page may have. `PageRules` has a `schema_type` rule that only accepts these, and `page_draft_create` and `page_update` take `schema_type`.
- `cms.contact.sent_query` names the query parameter of the address after a sent contact form. The default stays `verzonden`.
- `ContactFormGuard::verdict()` and the `ContactFormVerdict` enum. `ContactFormGuard::token()` takes `$resubmission`; a form shown again after an error gets a token that can be sent at once.
- `RecordRevision::keep()` returns the number of revisions kept per page.
- `ext-gd` is a declared requirement.

### Changed

- `UploadMedia` refuses a failed upload and a file larger than `cms.media.max_upload_kilobytes` (`0` switches the check off) with a `ValidationException` on `file`, and validates `alt`, `caption`, `focal_x` and `focal_y` with `MediaRules`. `alt` is now required.
- A contact form with a genuine but expired token shows "please try again" with a fresh token instead of pretending it was sent. A token that is too new still counts as a bot.
- `AbstractBlockType::label()` returns the translated label instead of the translation key, and the MCP block type summary shows it.
- `PageRules` allows a `canonical_url` of at most 255 characters, the size of its column.
- Only the media library items of a `MediaAsset` (also under a morph map alias) flush the page cache; media of other models no longer does.
- A transaction flushes the page cache once, however many content models it saves. The flush is tied to the transaction itself, so a rolled back savepoint or a failing commit callback of other code cannot stop later flushes.
- A block reorder that keeps the current order changes nothing and records no revision.
- The `media_list` and `redirects_list` searches qualify their columns with the table name.

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
[0.2.2]: https://github.com/J87NL/laravel-cms/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/J87NL/laravel-cms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/J87NL/laravel-cms/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/J87NL/laravel-cms/releases/tag/v0.1.0
