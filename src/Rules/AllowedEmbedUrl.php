<?php

declare(strict_types=1);

namespace J87NL\Cms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedEmbedUrl implements ValidationRule
{
    private const string QUERY_PATTERN = '#^[A-Za-z0-9_.,=&%;:+-]*$#';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::passes($value)) {
            return;
        }

        $fail('cms::validation.embed_not_allowed')->translate();
    }

    public static function passes(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('#\s|\\\\|\.\.|%2e#i', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment'])) {
            return false;
        }

        if (preg_match(self::QUERY_PATTERN, $parts['query'] ?? '') !== 1) {
            return false;
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        foreach (self::providers() as $provider) {
            if (in_array($host, $provider['hosts'], true) && preg_match($provider['path_pattern'], $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{hosts: list<string>, path_pattern: string}>
     */
    private static function providers(): array
    {
        $configured = config('cms.embeds', []);

        if (! is_array($configured)) {
            return [];
        }

        $providers = [];

        foreach ($configured as $provider) {
            if (! is_array($provider) || ! is_array($provider['hosts'] ?? null) || ! is_string($provider['path_pattern'] ?? null)) {
                continue;
            }

            $providers[] = [
                'hosts' => array_values(array_map(fn (mixed $host): string => (string) $host, $provider['hosts'])),
                'path_pattern' => $provider['path_pattern'],
            ];
        }

        return $providers;
    }
}
