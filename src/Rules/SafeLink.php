<?php

declare(strict_types=1);

namespace J87NL\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeLink implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const array SCHEMES = [
        'http',
        'https',
        'mailto',
        'tel',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::passes($value)) {
            return;
        }

        $fail('cms::validation.unsafe_link')->translate();
    }

    public static function passes(string $link): bool
    {
        $link = trim($link);

        if ($link === '' || preg_match('/\s/', $link) === 1 || str_contains($link, '\\')) {
            return false;
        }

        if (str_starts_with($link, '/')) {
            return ! str_starts_with($link, '//');
        }

        if (str_starts_with($link, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

        if (! in_array($scheme, self::SCHEMES, true)) {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return preg_match('#^https?://#i', $link) === 1 && filter_var($link, FILTER_VALIDATE_URL) !== false;
        }

        return true;
    }
}
