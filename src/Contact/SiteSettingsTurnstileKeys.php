<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use J87NL\Cms\Models\SiteSettings;

class SiteSettingsTurnstileKeys implements TurnstileKeys
{
    public function siteKey(): ?string
    {
        return SiteSettings::current()->contact_turnstile_site_key;
    }

    public function secretKey(): ?string
    {
        try {
            return SiteSettings::current()->contact_turnstile_secret_key;
        } catch (DecryptException) {
            Log::warning('The Turnstile secret key in the site settings cannot be decrypted, so Turnstile is off.');

            return null;
        }
    }
}
