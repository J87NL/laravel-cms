# Site settings

Site-wide settings live in one row of `cms_settings`, as `J87NL\Cms\Models\SiteSettings`. `SiteSettings::current()`
returns that row, or an unsaved empty instance when there is none.

Editors change the settings in the admin of `j87nl/laravel-vue-cms`. Its form adds length limits to the fields, see
[Site settings](https://github.com/J87NL/laravel-vue-cms/blob/main/docs/screens/settings.md) in its docs.

## Fields

| Field | Purpose |
|---|---|
| `site_name` | The site name. Falls back to `app.name` |
| `title_pattern` | The page title pattern, with `:title` as placeholder, for example `:title - J87` |
| `default_meta_description` | Description for pages without a meta description or text |
| `entity_type` | `Person` (default) or `Organization`, the `J87NL\Cms\Enums\EntityType` behind the site |
| `entity_name` | Name of the person or organization. Falls back to the site name |
| `entity_job_title` | Job title, only used for a person |
| `entity_email` | Email address of the person or organization |
| `entity_same_as` | Profile URLs, such as LinkedIn or GitHub |
| `entity_image_id` | A media asset: the photo of a person or the logo of an organization |
| `og_image_id` | Default share image for pages without their own |
| `robots_extra` | Extra lines for `robots.txt` |
| `llms_intro` | Introduction for `llms.txt`. Falls back to the default meta description |
| `contact_recipient` | Address the contact form mails to. Falls back to `cms.contact.recipient` |
| `contact_turnstile_site_key` | The Cloudflare Turnstile site key of the contact form, see [Contact form](contact-form.md#cloudflare-turnstile) |
| `contact_turnstile_secret_key` | The Cloudflare Turnstile secret key. Stored encrypted with `app.key` and hidden from `toArray()` and JSON |

The two Turnstile keys are not in `EDITABLE_ATTRIBUTES`, so a form for the other settings never sends or overwrites
them.
| `privacy_page_id` | The privacy statement page, linked below the contact form while it is published |

## Saving

```php
use J87NL\Cms\Actions\SaveSiteSettings;

app(SaveSiteSettings::class)->handle([
    'site_name' => 'J87',
    'title_pattern' => ':title - J87',
    'entity_type' => 'Person',
    'entity_name' => 'Johan Montenij',
    'entity_same_as' => "https://github.com/J87NL\nhttps://www.linkedin.com/in/example",
]);
```

`SaveSiteSettings::handle(array $attributes): SiteSettings` fills the fields in `SiteSettings::EDITABLE_ATTRIBUTES`
and saves the row. `entity_same_as` accepts a list or a string with one URL per line. Empty lines are skipped,
duplicates removed, and a value that is not a full `http(s)://` URL throws a `ValidationException`.

The Turnstile keys have their own rules, so a form never has to send the secret back:

- `contact_turnstile_site_key`, when present, is trimmed and stored, an empty value as `null`;
- a non-empty `contact_turnstile_secret_key` replaces the stored secret;
- an empty or missing one keeps the stored secret;
- `remove_contact_turnstile_secret_key` (`SiteSettings::REMOVE_TURNSTILE_SECRET`) set to `true`, with an empty secret,
  clears it.

The other fields are not validated by the action. Validate them in your request.

## Helpers

| Method | Returns |
|---|---|
| `siteName()` | `site_name`, or `app.name` when empty |
| `entityName()` | `entity_name`, or the site name when empty |
| `title(string $pageTitle)` | The page title through the pattern, see below |
| `sameAs()` | The profile URLs, trimmed, without empty values |
| `robotsLines()` | The non-empty lines of `robots_extra` |
| `privacyUrl()` | The URL of the privacy page while it is published, otherwise `null` |
| `hasTurnstileSecret()` | Whether a Turnstile secret key is stored, without decrypting it |

## Title pattern

`title()` builds the `<title>` of a page without a meta title:

- an empty page title, or one equal to the site name, gives the site name;
- a pattern with `:title` replaces the placeholder with the page title;
- an empty pattern, or one without `:title`, gives `{page title} | {site name}`.

A page with a `meta_title` uses that title as is, without the pattern.
