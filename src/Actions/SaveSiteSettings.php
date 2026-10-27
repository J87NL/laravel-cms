<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\SiteSettings;

class SaveSiteSettings
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): SiteSettings
    {
        $settings = SiteSettings::current();

        $settings->fill(Arr::only($attributes, SiteSettings::EDITABLE_ATTRIBUTES));

        if (array_key_exists('entity_same_as', $attributes)) {
            $settings->entity_same_as = $this->urls($attributes['entity_same_as']);
        }

        if (array_key_exists(SiteSettings::TURNSTILE_SITE_KEY, $attributes)) {
            $settings->setAttribute(SiteSettings::TURNSTILE_SITE_KEY, trim((string) $attributes[SiteSettings::TURNSTILE_SITE_KEY]) ?: null);
        }

        $secret = trim((string) ($attributes[SiteSettings::TURNSTILE_SECRET] ?? ''));

        if ($secret !== '') {
            $settings->setAttribute(SiteSettings::TURNSTILE_SECRET, $secret);
        }

        if ($secret === '' && filter_var($attributes[SiteSettings::REMOVE_TURNSTILE_SECRET] ?? false, FILTER_VALIDATE_BOOL)) {
            $settings->setAttribute(SiteSettings::TURNSTILE_SECRET, null);
        }

        $settings->save();

        return $settings;
    }

    /**
     * @return list<string>
     */
    private function urls(mixed $value): array
    {
        $lines = is_array($value) ? $value : preg_split('/\R/', (string) $value);
        $urls = [];

        foreach ($lines ?: [] as $line) {
            $url = trim((string) $line);

            if ($url === '') {
                continue;
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('#^https?://#i', $url) !== 1) {
                throw ValidationException::withMessages([
                    'entity_same_as' => __('cms::validation.invalid_profile_url', [
                        'url' => $url,
                    ]),
                ]);
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }
}
