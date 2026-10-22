# Security

Public pages render content that editors, and through the [MCP tools](mcp-tools.md) assistants, have written. The
package treats that content as untrusted: it cleans it on save, checks it again on render, and sends strict headers.

## Security headers

`J87NL\Cms\Http\Middleware\AddSecurityHeaders` runs on every package route. Each header except the CSP can be switched
off in `cms.security_headers`; `enabled => false` drops them all.

| Header | Value |
|---|---|
| `Content-Security-Policy` | See below |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Content-Type-Options` | `nosniff` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` |
| `Strict-Transport-Security` | `max-age={hsts_max_age}`, only on secure requests |

### Content-Security-Policy

The default policy:

```text
default-src 'self'; img-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; font-src 'self';
script-src 'none'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; frame-src 'none'
```

Public pages run no scripts at all. `style-src-attr 'unsafe-inline'` is there for the inline `object-position` style
of the image component.

Add sources per directive in `cms.security_headers.csp`. A source replaces `'none'` and is added to the other
sources:

```php
'security_headers' => [
    'csp' => [
        'script-src' => ["'self'"],
        'img-src' => ['https://cdn.example.org'],
        'font-src' => ['https://fonts.gstatic.com'],
        'style-src' => ['https://fonts.googleapis.com'],
    ],
],
```

On a page that contains an embed `iframe`, the hosts from `cms.embeds` that the page actually uses are added to
`frame-src` as `https://{host}`. Other pages keep `frame-src 'none'`.

## Rich text

`J87NL\Cms\Html\RichTextSanitizer` cleans rich text with `symfony/html-sanitizer`, when a block is saved and again when
it is rendered. A sanitizer change therefore also applies to content stored earlier.

- Allowed elements: `h2`, `h3`, `h4`, `p`, `br`, `ul`, `ol`, `li`, `strong`, `em`, `blockquote`, `table`, `thead`,
  `tbody`, `tr`, `th` (`colspan`, `rowspan`, `scope`), `td` (`colspan`, `rowspan`) and `a` (`href`, `title`,
  `target`).
- Wrapper elements such as `div`, `span`, `b`, `i`, `h1`, `code` and `section` are removed, but their text is kept.
- Every other element, such as `script`, `style`, `iframe` and `img`, is removed with its content. All other
  attributes, event handlers and `style` included, are removed.
- Links may use `http`, `https`, `mailto`, `tel` or a relative URL. A link starting with `//`, containing a backslash,
  or starting with `http:` or `https:` without `//` loses its `href`.
- `target` is only kept as `_blank`. Every link gets `rel="noopener"`.
- Input longer than 500,000 characters is cut off.

## Links

The call to action block, the MCP menu tool and the menu item form of `j87nl/laravel-vue-cms` validate links with
`J87NL\Cms\Rules\SafeLink`. A link passes when it is a path starting with a single `/`, a `#fragment`, a valid `http://`
or `https://` URL, or a `mailto:` or `tel:` link, without whitespace or backslashes. `SafeLink::passes(string $link)` is
checked again on render.

## Embeds

`J87NL\Cms\Rules\AllowedEmbedUrl` only accepts a URL that:

- uses `https`, without user info, port or fragment;
- contains no whitespace, backslash, `..` or `%2e`;
- has a query string of letters, digits and `_ . , = & % ; : + -` only;
- has a host that is exactly one of the `hosts` of a provider in `cms.embeds`, and a path that matches its
  `path_pattern`.

The URL is checked again on render, so a stored URL that fails renders nothing. The iframe has
`sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"`, `loading="lazy"` and
`referrerpolicy="strict-origin-when-cross-origin"`.

To allow another provider, add it to `cms.embeds`:

```php
'embeds' => [
    // the youtube and openstreetmap entries...
    'vimeo' => [
        'hosts' => [
            'player.vimeo.com',
        ],
        'path_pattern' => '#^/video/[0-9]+$#',
    ],
],
```

The validation message and the help text in the package's translations only name YouTube and OpenStreetMap. Override
`cms::validation.embed_not_allowed` and `cms::blocks.embed.help.url` when you add a provider.

## Uploads

Uploaded images are decoded and encoded again with GD, which drops EXIF data such as GPS locations. Images above
`cms.media.max_pixels` are refused before they are decoded. See [Media](media.md#uploading).

## Contact form

The contact form has no CSRF token, because public routes have no session. Instead it uses a honeypot, a signed
timestamp, a per-IP and a site-wide hourly limit, and a per-minute route throttle. See [Contact form](contact-form.md).

## Previews

Preview links are signed and expire after `cms.preview.minutes`. Anyone with a valid draft preview link can see the
page. Only hand them out to users who may edit pages, as `j87nl/laravel-vue-cms` does.

The preview of a pending revision shows changes nobody has approved yet, so it is gated harder: besides the signature
it needs a signed-in user with `cms.pages.edit` (`cms.preview.pending_middleware`, by default `web` and `auth`), and the
link names the pending revision it was made for. Once that revision is published or discarded the link answers 404,
also when a new pending revision has been started since, so a shared link never shows later changes. See
[Previews](previews.md).

## JSON-LD

The JSON-LD is encoded with `JSON_HEX_TAG` and `JSON_HEX_AMP`, so page content cannot close the `script` element.

## The admin

This package does not authenticate or authorize anything on its public routes, and it ships no admin routes. The
actions do not check permissions either: the code that calls them must. See [Permissions](permissions.md).

The admin of `j87nl/laravel-vue-cms` runs its routes behind `auth` and a permission check, and locks the page
while it writes. See its [security documentation](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/security.md).
