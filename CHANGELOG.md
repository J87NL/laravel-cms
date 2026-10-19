# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Security

- Requires `symfony/html-sanitizer` ^7.4.13 or ^8.0.13, so a host can no longer resolve versions with the URL attribute sanitising vulnerabilities (CVE-2026-45064, CVE-2026-45066, CVE-2026-45753, CVE-2026-48760, CVE-2026-48761).

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
- Security headers on public routes.

[Unreleased]: https://github.com/J87NL/laravel-cms/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/J87NL/laravel-cms/releases/tag/v0.1.0
