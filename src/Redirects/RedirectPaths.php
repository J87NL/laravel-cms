<?php

declare(strict_types=1);

namespace J87NL\Cms\Redirects;

use Illuminate\Support\Str;

class RedirectPaths
{
    public function from(string $value): string
    {
        $path = $this->localPath(trim($value)) ?? trim($value);

        $path = (string) strtok($path, '?#');

        return Str::lower(trim(rawurldecode($path), '/'));
    }

    public function to(string $value): string
    {
        $value = trim($value);
        $local = $this->localPath($value);

        if (is_null($local) && preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        $path = trim($local ?? $value);

        return sprintf('/%s', ltrim($path, '/'));
    }

    public function targetPath(string $to): ?string
    {
        if (! str_starts_with($to, '/')) {
            return null;
        }

        $path = (string) strtok($to, '?#');

        return Str::lower(trim($path, '/'));
    }

    private function localPath(string $value): ?string
    {
        if (preg_match('#^https?://#i', $value) !== 1) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(Str::lower($host), $this->localHosts(), true)) {
            return null;
        }

        $path = (string) (parse_url($value, PHP_URL_PATH) ?? '/');
        $query = parse_url($value, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? sprintf('%s?%s', $path, $query) : $path;
    }

    /**
     * @return list<string>
     */
    private function localHosts(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [];
        }

        $host = Str::lower($host);

        return array_values(array_unique([
            $host,
            Str::startsWith($host, 'www.') ? Str::after($host, 'www.') : sprintf('www.%s', $host),
        ]));
    }
}
