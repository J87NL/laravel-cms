# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.8.0] - 2026-10-30

### Added

- An optional `sha256` on `media_upload`: the SHA-256 of the file bytes before encoding. The decoded bytes are checked against it before anything else, and a mismatch is refused as a damaged transfer ("The upload arrived damaged: the sha256 checksum does not match the N bytes that were received"), so a miscopied base64 string no longer looks like an unreadable image. Without `sha256` nothing changes.
- Chunked uploads through three new MCP tools, for large files or files with long repetitive runs: `media_upload_start` (file name, size, `sha256` of the whole file and the image fields; returns an `upload_id` and the chunk count), `media_upload_chunk` (one chunk of `MediaUploadSession::CHUNK_BYTES`, 2048 bytes, as base64 with its own `sha256`; a damaged chunk is refused on its own and sending an index again replaces it) and `media_upload_finish` (lists missing chunks, checks the whole file's size and `sha256`, then runs the same type check and `UploadMedia` as `media_upload`). All three need `cms.media` and a full grant and are not marked destructive.
- Uploads are `J87NL\Cms\Models\MediaUploadSession` rows in the new table `cms_media_upload_sessions`, with their chunks on the disk `cms.media.chunked_uploads.disk` (default `local`) under `cms.media.chunked_uploads.directory`. An upload belongs to the user who started it; for others it is not found. It expires `cms.media.chunked_uploads.ttl_minutes` (default 60) after its start or its last chunk, and a user can have at most `cms.media.chunked_uploads.max_open_per_user` (default 5) open uploads.
- The command `cms:prune-media-uploads`, scheduled every hour (also when `cms.scheduling.enabled` is `false`), deletes expired uploads with their chunks and chunk directories without an upload.
- When `cms.media.max_upload_kilobytes` is `0`, MCP uploads (base64, chunked and `source_url`) still stop at `ImageUploader::HARD_MAX_BYTES` (64 MB).
- The MCP tool `media_delete` (`J87NL\Cms\Mcp\Tools\MediaDeleteTool`): permanently deletes 1 to 25 distinct images through `DeleteMedia`, each id on its own, and returns per id `deleted`, `in_use` (with `usage_count`), `not_found` or `failed` (an unexpected error for that id, which is reported; the other ids are still handled), with the alt text and file name of deleted and in-use images. Images in use (blocks, page `og_image_id`, pending revisions, site settings) are refused and left alone. It needs `cms.media` and a full grant and is marked destructive, so the J87 MCP hub starts it disabled.
- `source_url` on `media_upload`, instead of `content_base64`, for an image that is already online. Only public `https` on port 443: every address the host resolves to must be public (private, loopback, link-local, shared, reserved, documentation, benchmarking and multicast IPv4 ranges are refused; IPv6 only in global unicast outside its translation and documentation ranges), also after each of at most 3 redirects. The request goes through Guzzle's curl handler without a proxy, and the connection is pinned to the checked address with `CURLOPT_RESOLVE`. The whole download times out after 10 seconds and stops past `cms.media.max_upload_kilobytes`. `file_name` defaults to the last part of the address. Host names are resolved by the new contract `J87NL\Cms\Media\Remote\HostResolver`, bound to `DnsHostResolver` unless the app binds its own.

### Changed

- The descriptions of `media_upload` and `Cms::mcpInstructions()` mention `sha256`, the chunked tools, `source_url` and `media_delete`; `media_list` points to `media_delete`. Replacing the file of an image stays admin-only. `Cms::mcpTools()` now returns 27 tools.
- The last step of every MCP upload (type check with `finfo`, then `UploadMedia`) lives in `J87NL\Cms\Mcp\ImageUploader`.

### Upgrade notes

- Run `php artisan migrate` for the `cms_media_upload_sessions` table.
- Run the Laravel scheduler (it already runs for `cms:publish-scheduled`) so expired chunked uploads are pruned.
- The chunk disk must be private; the default `local` disk is. An app with a published `config/cms.php` gets the new `chunked_uploads` keys from the package defaults.
- `media_upload` with `source_url` makes outgoing https requests from the app server, directly and never through `HTTPS_PROXY`/`HTTP_PROXY`. A server that reaches the internet only through a proxy cannot use it. Block it in your firewall if you do not want outgoing requests; the address checks already keep it away from internal networks.

## [0.7.0] - 2026-10-29

### Added

- The MCP tool `media_upload` (`J87NL\Cms\Mcp\Tools\MediaUploadTool`), so assistants can add images to the media library and use their ids in block data (`media_id`, `media_ids`) or as `og_image_id`. It takes `file_name`, the file as base64 in `content_base64` (a `data:…;base64,` prefix is allowed), `alt` and optionally `caption`, `focal_x` and `focal_y`, and returns the new image in the shape of `media_list`. It needs `cms.media` and a full grant, and is not marked destructive.
- The size is checked against `cms.media.max_upload_kilobytes` before anything is written to disk: `content_base64` may be no longer than a file of that size needs as base64 (plus room for a prefix and line breaks), and the decoded bytes are checked again. The type is read from the content with `finfo`, so only JPEG, PNG, WebP and GIF pass, whatever the file name says (no SVG). The upload then goes through `UploadMedia`, with the same attribute rules, re-encoding without metadata and queued WebP conversions as an admin upload. The temporary file is always removed.

### Changed

- The description of `media_list` and `Cms::mcpInstructions()` point to `media_upload`. Deleting and replacing images stays admin-only.

### Upgrade notes

- No migration is needed. `Cms::mcpTools()` now returns 23 tools; an app server that sets `$defaultPaginationLength` must still list all of its tools on one page when the hub does not follow `nextCursor`.

## [0.6.0] - 2026-10-27

### Added

- Optional Cloudflare Turnstile on the contact form. It is off by default and switches on when the site settings hold both a site key and a secret key. The form then shows the Turnstile widget (managed mode) and loads Cloudflare's script once per page. A submission must carry a `cf-turnstile-response` token that Cloudflare's `siteverify` accepts, within `cms.contact.turnstile.timeout` seconds (default 3). This check runs right after the honeypot and timestamp check, so rspamd never scans a bot. A missing or rejected token is treated like a failed bot check: no mail, the sent redirect, a hit on the hourly IP limit and an `info` log with the new reason `turnstile` (`ContactFormRejection::Turnstile`).
- The site settings fields `contact_turnstile_site_key` and `contact_turnstile_secret_key`, with a new migration. Both are left out of `SiteSettings::EDITABLE_ATTRIBUTES`, so a form for the other settings leaves them alone. The secret key is cast `encrypted` and hidden from `toArray()` and JSON. `SaveSiteSettings` stores a given site key (trimmed, empty as `null`) and a non-empty secret, keeps the stored one when it is empty, and clears it with `remove_contact_turnstile_secret_key` (`SiteSettings::REMOVE_TURNSTILE_SECRET`). `SiteSettings::hasTurnstileSecret()` tells whether one is stored.
- The keys are read through the new contract `J87NL\Cms\Contact\TurnstileKeys`. The package binds it to `SiteSettingsTurnstileKeys` unless the app has bound it already, so an app can serve the keys from its own settings.
- On a page with the Turnstile widget, the Content-Security-Policy allows `https://challenges.cloudflare.com` in `script-src` and `frame-src`.
- The text `cms::blocks.contact_form.turnstile_noscript`, shown to visitors without JavaScript when Turnstile is on.

### Upgrade notes

- Run `php artisan migrate` for the two new `cms_settings` columns. Turnstile stays off until both keys are set.
- When Cloudflare cannot be reached, answers with an error or refuses the secret key, a submission with a token is accepted and a warning is logged (fail open, like rspamd). A submission without a token, or with a token longer than 2048 characters, is always rejected.
- An app that published `blocks/contact-form.blade.php` must add the widget itself; see the package view.

## [0.5.1] - 2026-10-26

### Fixed

- The package config is now merged into the app's `config/cms.php` recursively, so keys that were added after an app published its config, including nested ones such as `cms.contact.rspamd.url` (env `CMS_CONTACT_RSPAMD_URL`), `cms.contact.per_minute` and `cms.contact.max_links`, get their package default. Until now only the top level was merged, so a published `contact` array replaced the package's whole `contact` array. Every value in the app's config still wins. A list in the app's config (such as `blocked_keywords`) replaces the package's list as a whole.

### Upgrade notes

- No changes are needed. The statement in the 0.5.0 upgrade notes that apps with a published config fall back to the defaults was only true for top-level keys: with 0.5.0, an app with a published `contact` array needed to copy the new `contact` keys, or the nested ones (such as `rspamd.url`) were missing. From 0.5.1 on they are filled in.

## [0.5.0] - 2026-10-26

### Added

- A limit on sent contact form messages per IP address per block per minute: `cms.contact.per_minute` (default 3). It is the named rate limiter `cms-contact` (`ContactFormLimiter::NAMED_LIMITER`), which an app can replace with `RateLimiter::for()`. Only queued messages count. Over the limit, the page is shown again with the new text `cms::blocks.contact_form.too_fast`, status `429` and a `Retry-After` header.
- Spam checks on the message after validation: a message with more than `cms.contact.max_links` links (default 2, `null` for any number), a message that holds nothing but links (`cms.contact.reject_only_links`, default `true`), a message with an entry of `cms.contact.blocked_keywords` (default empty, matched case-insensitively anywhere, also inside words) in the name, address or message, and a message that is not valid UTF-8. Such a message gets the same redirect as a sent one, but no mail is sent, and it counts toward the hourly IP limit, as a bot post does.
- Optional rspamd scoring: with `cms.contact.rspamd.url` set (env `CMS_CONTACT_RSPAMD_URL`, for example `http://127.0.0.1:11333/checkv2`), the composed mail is posted to rspamd's `checkv2` endpoint with a timeout of `cms.contact.rspamd.timeout` seconds (default 2). The action `reject` or a score of at least `cms.contact.rspamd.threshold` (default 6) rejects the message as spam. When rspamd cannot be reached or gives no score, the message is sent and a warning is logged.
- Every rejected submission (bot check or spam check) is logged at `info` level with the block id and the reason, without the message, the sender or the IP address.

### Upgrade notes

- No changes are needed: the new keys have defaults. An app with a published `config/cms.php` can copy the new `contact` keys from the package config; without them the defaults apply. Set `CMS_CONTACT_RSPAMD_URL` in `.env` to use rspamd.
- A visitor can now send at most 3 messages per minute through one contact block. Raise `cms.contact.per_minute`, set it to `0` to switch it off, or replace the `cms-contact` rate limiter to change that.
- Messages with three or more links are no longer mailed. Set `cms.contact.max_links` to `null` to keep the old behaviour.

## [0.4.0] - 2026-10-23

### Added

- `DiffPendingRevision::handle(Page $page, ?Revision $revision = null, bool $withWords = true): ?array` returns a structured diff of the pending revision against the live page, as publishing would apply it: changed page fields with their live and pending value, and every block with its status (`added`, `removed`, `changed`, `unchanged`), whether it moved, its position before and after, its visibility before and after, and its changed data values by dot path. Rich text is compared as plain text, and changed text values carry a word-level diff (`J87NL\Cms\Pages\WordDiff`). A rich text change that leaves the text alone is flagged with `markup_changed`, and changed `href`, `src` and `srcset` values are listed under `links`. A removed block is shown after the nearest block before it that did not move, or, when there is none, before the nearest block after it that did not move; when no block stays, the removed blocks come first in their live order.
- A signed, expiring, `noindex` preview of a page with its pending revision applied: route `cms.preview.pending` (`GET /cms/preview/{page}/pending/{revision}`) and `PreviewController::pendingUrl(Page $page, Revision $revision)`. Nothing is written; the page renders from memory. It needs a signed-in user with `cms.pages.edit` (new config key `cms.preview.pending_middleware`, default `['web', 'auth']`), and answers 404 once the revision is no longer the page's pending revision.
- `PendingRevisionApplier::plan()` returns a `J87NL\Cms\Pages\PendingRevisionPlan`: what publishing would change, without writing. `PendingRevisionPlan::page()` gives the resulting page with its blocks for rendering. `PublishPage` and taking a page offline apply the same plan, so the diff, the preview and publishing always agree.
- `Page::visibleBlocks()` returns the visible blocks in display order, from the loaded `blocks` relation when it is loaded. The block renderer and the SEO tags use it.
- `page_get` (and the other tools that return a page) adds `pending_revision.changes`: a short summary of what publishing would change (fields with their live and pending value, and the added, removed, changed and moved blocks with the changed data keys, the keys whose markup alone changed and the changed link targets).
- `J87NL\Cms\Html\PlainText` turns rich text into plain text (`AbstractBlockType::plain()` uses it) and lists the `href`, `src` and `srcset` values in HTML, read with the HTML5 parser (`PlainText::links()`).

### Changed

- The block renderer and the SEO tags use the page's `blocks` relation when it is already loaded instead of querying the visible blocks again (`Page::visibleBlocks()`). An app that eager-loads `blocks` with constraints of its own now renders the visible blocks among those.

## [0.3.1] - 2026-10-23

### Security

- Require a `laravel/mcp` release with the fixes of 2026-10-21: `^0.9.7|^1.1.1` instead of `^0.7`. Those releases limit dynamic client registration to 10 redirect URIs of at most 1024 characters each, and check that a resource advertises the OAuth metadata a caller supplies. The 0.7 and 0.8 lines got no fix, so an app that requires this package can no longer stay on them. The package code works unchanged on both lines.

### Upgrade notes

- `laravel/mcp` moves from 0.7 to 0.9 or 1.x. Run `composer update j87nl/laravel-cms laravel/mcp -W`: a plain `composer update j87nl/laravel-cms` keeps an app on 0.3.0. An app with its own MCP servers, tools or OAuth routes should check the `laravel/mcp` upgrade notes for 0.8, 0.9 and 1.0 before it updates.

## [0.3.0] - 2026-10-22

### Added

- The `link_tiles` block type (`LinkTilesBlock`): an optional heading and a reorderable list of tiles with a name, an optional image and an optional `https://` link, rendered as image or name. The `rel` value is chosen per block: `noopener`, `me noopener` or `sponsored noopener`. Images on tiles count as in use. It replaces app blocks such as sponsors and social links.
- `<x-cms::image>` takes an optional `alt` attribute that replaces the asset's alt text.
- `ReplaceMediaFile` gives a media asset a new file in place. The asset keeps its id, alt text, caption and focal point, so every block that uses it shows the new file. The file gets the same checks as an upload, the conversions are made again, the old files are deleted after the transaction commits, and the page cache is flushed.
- `J87NL\Cms\Media\UploadedImage` holds the upload size check and file naming that `UploadMedia` and `ReplaceMediaFile` share.
- Pending revisions: a published page can hold one pending revision (`cms_revisions.is_pending`) with changes that are not live yet. `Page::pendingRevision()`, the `Revision` scopes `history()` and `pending()`, and `J87NL\Cms\Pages\PendingRevisionEditor` to change it.
- `PublishPage` applies what the pending revision of a page changed compared with the live page it started from (`PendingRevisionApplier`, blocks matched by id), and publishes a page that is not published yet. `DiscardPendingRevision` throws a pending revision away.
- `ChangePageStatus::applyLocked()` changes the status of a page whose row the caller already locked.
- Taking a page offline applies its pending revision to the page; a slug or parent change that cannot be applied is skipped with a logged warning, so it never blocks the status change.
- MCP tools `page_update_live`, `block_add_live`, `block_update_live`, `block_remove_live` and `blocks_reorder_live` change a page directly, also when it is published. They need `cms.pages.publish` and are marked destructive.
- MCP tools `page_publish`, `page_unpublish` and `revision_discard`, marked destructive. They need `cms.pages.edit` and `cms.pages.publish` and check the `cms.pages.write` gate.
- `pages_list` and `page_get` report `has_pending_revision`; `page_get` returns `pending_revision`.
- A block added on the live page after a pending revision started is taken into that pending revision and its base unchanged (`PendingRevisionEditor::withLiveBlocks()`), so the draft tools can change or remove it instead of answering that it was not found.

### Changed

- The `UploadMedia` constructor takes an `UploadedImage` next to the `ImageCleaner`. Code that builds it with `new` must pass one; resolving it from the container keeps working.
- `DeleteMedia` checks the usage and deletes the rows inside a transaction that locks the asset row, and removes the files only after the commit. A rolled back delete keeps the files. The constructor takes the media library `Filesystem`.
- The MCP draft tools (`page_update`, `block_add`, `block_update`, `block_remove`, `blocks_reorder`) no longer refuse a published page: they store the change in its pending revision and leave the live page, the revision history and the page cache alone. They skip the `cms.pages.write` gate for those writes. `block_update` and `block_remove` take an optional `page_id` for blocks that only exist in the pending revision.
- `writable` in `pages_list` and `page_get` is only `false` for a page with a planned publication.
- Revision snapshots store the block `id`.
- `ChangePageStatus` takes `PendingRevisionEditor` and `PendingRevisionApplier` in its constructor; a subclass that calls the parent constructor must pass them.
- `Page::revisions()` and revision pruning leave out the pending revision, and `RestoreRevision` refuses it.
- `MediaUsage` counts images used in pending revisions, so they cannot be deleted.
- The package now ships 22 MCP tools; set `$defaultPaginationLength = 50` on the app's MCP server when the client does not page through `tools/list`.

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

[0.8.0]: https://github.com/J87NL/laravel-cms/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/J87NL/laravel-cms/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/J87NL/laravel-cms/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/J87NL/laravel-cms/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/J87NL/laravel-cms/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/J87NL/laravel-cms/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/J87NL/laravel-cms/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/J87NL/laravel-cms/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/J87NL/laravel-cms/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/J87NL/laravel-cms/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/J87NL/laravel-cms/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/J87NL/laravel-cms/releases/tag/v0.1.0
