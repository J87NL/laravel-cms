# Contact form

The `contact_form` block renders a form with name, email address and message. A submission is mailed to the site
owner, with the visitor as `Reply-To`. The form works without JavaScript, cookies or a session, so it has no CSRF
token. It is protected by a honeypot, a signed timestamp and rate limits instead, and the message itself is checked
for spam.

## Setup

1. Set the recipient in the site settings (`contact_recipient`), or as a fallback in `cms.contact.recipient`.
2. Run a queue worker: the mail is sent with `Mail::queue()`.
3. Hide the honeypot field in your stylesheet:

```css
.cms-contact__trap { position: absolute; left: -10000px; }
```

Without a recipient, a valid submission shows "The form cannot send messages right now" with status `503`.

When the site settings have a published privacy page (`privacy_page_id`), the form links to it.

## The block

| Field | Purpose |
|---|---|
| `title` | Heading above the form |
| `intro` | Text above the form |
| `button_label` | The button text, "Send" when empty |
| `success_message` | The text shown after sending, a default thank-you text when empty |

## Handling a submission

The form posts to `POST /cms/contact/{block}` (`cms.contact`). `J87NL\Cms\Http\Controllers\ContactFormController`
handles it in this order:

1. The block must exist, be a visible `contact_form` block on a public page, and the block type must not be
   disabled. Otherwise the answer is `404`.
2. When the visitor's IP address has reached `cms.contact.per_hour` submissions in the past hour, the page is shown
   again with an error, status `429` and a `Retry-After` header.
3. When the visitor has sent `cms.contact.per_minute` messages through this block in the past minute (the
   `cms-contact` rate limiter, see [Rate limits](#rate-limits)), the page is shown again with
   `cms::blocks.contact_form.too_fast`, status `429` and a `Retry-After` header.
4. The bot check: the honeypot field `website` must be empty, and the hidden `form_token` must carry a valid
   signature for this block and be at least `cms.contact.min_seconds` old. A submission that fails counts toward the
   IP limit and gets the same redirect as a sent message, but no mail is sent.
5. A genuine token older than `cms.contact.max_seconds` has expired. The page is shown again with the message
   `cms::blocks.contact_form.expired`, the entered values and a fresh token, status `422`. It counts toward the IP
   limit, and no mail is sent. A page shown again after an error carries a token that is already
   `cms.contact.min_seconds` old, so the visitor can send it again at once.
6. Validation: `name` (at most 120 characters), `email` (a valid address, at most 254 characters) and `message` (at
   most 5,000 characters) are required. On failure the page is shown again with the errors and the entered values,
   status `422`. Failed validation does not count toward the IP limit.
7. Without a recipient, the page is shown again with an error, status `503`.
8. The spam checks, see [Spam checks](#spam-checks). A message that fails them counts toward the IP limit and gets the
   same redirect as a sent message, but no mail is sent.
9. When the site has sent `cms.contact.global_per_hour` mails in the past hour, the page is shown again with an
   error, status `429`.
10. The mail is queued, the submission counts toward the IP limit and the per-minute limit, and the visitor is
    redirected (`303`) to `{page}?verzonden={block id}#contact-{block id}`, where the block shows the success message.
    Set `cms.contact.sent_query` to use another name than `verzonden` for that query parameter.

The route also has `throttle:30,1`, which limits all posts to this route to 30 per minute per IP address.

Every rejected submission (bot check or spam check) is logged at `info` level with the block id and the reason
(`bot_check`, `invalid_encoding`, `only_links`, `too_many_links`, `blocked_keyword` or `rspamd`), without the name,
address, message or IP address.

## Rate limits

- `cms.contact.per_hour` (default 5): submissions per IP address per hour, over all blocks. Sent messages, bot posts,
  spam and expired forms count; typos do not.
- `cms.contact.per_minute` (default 3): sent messages per IP address per contact block per minute. Only messages that
  were actually queued count. Set it to `0` or `null` to switch it off.
- `cms.contact.global_per_hour` (default 30): mails per hour for the whole site.

The per-minute limit is the named rate limiter `cms-contact` (`ContactFormLimiter::NAMED_LIMITER`), registered by the
package. An app can replace it in the `boot()` of its own service provider; the callback gets the request, and the
block id is `$request->route('block')`:

```php
RateLimiter::for('cms-contact', fn (Request $request): array => [
    Limit::perMinute(3)->by(sprintf('%s|%s', $request->ip(), $request->route('block'))),
    Limit::perHour(10)->by(sprintf('%s|%s', $request->ip(), $request->route('block'))),
]);
```

## Spam checks

After validation, the message is checked in this order. The first check that fails stops the rest.

1. Invalid text: a message that is not valid UTF-8 (reason `invalid_encoding`).
2. Only links: a message whose text, apart from its links, holds no letter or digit. Switch off with
   `cms.contact.reject_only_links`.
3. Too many links: more than `cms.contact.max_links` (default 2) links. Every `http://`, `https://` or `www.` that
   does not follow a letter, digit, `/`, `.`, `@`, `:`, `_` or `-` counts as a link, so `https://www.example.org`
   is one link. Set it to `null` to allow any number.
4. Blocked keywords: any entry of `cms.contact.blocked_keywords` (empty by default) found in the name, the address or
   the message, case-insensitive. It matches inside words too: `sex` also blocks `Essex`.
5. rspamd, when `cms.contact.rspamd.url` is set (env `CMS_CONTACT_RSPAMD_URL`), see below.

### rspamd

The package posts the mail as it would be sent (from the visitor's name and address, to the recipient, with the
subject and the plain text body) as a raw message to rspamd's `checkv2` endpoint, for example
`http://127.0.0.1:11333/checkv2` on the normal worker. The message counts as spam when rspamd answers with the action
`reject`, or with a score at or above `cms.contact.rspamd.threshold` (default 6, rspamd's default `add header` score).

The visitor's IP address is not passed on: rspamd would then check SPF and DMARC of the visitor's own domain against
the web server's visitor IP, and genuine messages from, say, a Gmail address would fail them.

rspamd gets `cms.contact.rspamd.timeout` seconds (default 2) to connect and answer. When it cannot be reached, answers
with an error or without a score, the message is sent anyway and a warning is logged.

Errors and old input are passed on within the same request (no session), so the form is rendered again in the response
to the post itself.

## The token

`J87NL\Cms\Contact\ContactFormGuard::token(int $blockId)` makes the value of the hidden `form_token` field: the
current time and an HMAC of the block id and that time, signed with `app.key`. Changing `app.key` invalidates all
open forms.

The token is part of the page HTML, so a page served from the [page cache](page-cache.md) carries the token from the
moment it was rendered. Keep `cms.cache.ttl` (default 86,400 seconds) well below `cms.contact.max_seconds` (default
172,800 seconds). Otherwise a visitor can get a cached form whose token has already expired, and has to send the form
a second time.

`ContactFormGuard::verdict(Request $request, int $blockId)` returns a `J87NL\Cms\Contact\ContactFormVerdict`:
`Human`, `Bot` or `Expired`. `looksHuman()` is true only for `Human`.

## The mail

The mail is `J87NL\Cms\Mail\ContactFormSubmitted`, a plain text mail with the view `cms::mail.contact-form`:

- subject: "Message via {site name}: {page title}" (`cms::blocks.contact_form.mail_subject`);
- `Reply-To`: the visitor's name and address;
- body: the page URL, the visitor's name and address, and the message.

The subject and the `Reply-To` name are squished to one line. The sender is the app's default `mail.from` address.

To change the texts, override the `contact_form` strings in `lang/vendor/cms/{locale}/blocks.php`, or publish the
views and edit `blocks/contact-form.blade.php` or `mail/contact-form.blade.php`.
