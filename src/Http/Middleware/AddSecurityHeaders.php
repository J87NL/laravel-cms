<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use J87NL\Cms\Contact\Turnstile;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function __construct(
        private readonly Turnstile $turnstile,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('cms.security_headers.enabled', true)) {
            return $response;
        }

        $headers = $response->headers;

        $headers->set('Content-Security-Policy', $this->policy($response));

        if ($this->switchedOn('referrer_policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if ($this->switchedOn('nosniff')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        if ($this->switchedOn('permissions_policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');
        }

        if ($this->switchedOn('hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', sprintf('max-age=%d', (int) config('cms.security_headers.hsts_max_age', 31536000)));
        }

        return $response;
    }

    private function switchedOn(string $header): bool
    {
        return (bool) config(sprintf('cms.security_headers.%s', $header), true);
    }

    private function policy(Response $response): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'img-src' => ["'self'"],
            'style-src' => ["'self'"],
            'style-src-attr' => ["'unsafe-inline'"],
            'font-src' => ["'self'"],
            'script-src' => ["'none'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'frame-src' => ["'none'"],
        ];

        $extra = config('cms.security_headers.csp', []);

        foreach (is_array($extra) ? $extra : [] as $directive => $sources) {
            $directives = $this->allow($directives, (string) $directive, array_values(array_map('strval', (array) $sources)));
        }

        $frameSources = array_map(fn (string $host): string => sprintf('https://%s', $host), $this->embedHosts($response));

        if ($frameSources !== []) {
            $directives = $this->allow($directives, 'frame-src', $frameSources);
        }

        if ($this->showsTurnstile($response)) {
            $directives = $this->allow($directives, 'script-src', [Turnstile::HOST]);
            $directives = $this->allow($directives, 'frame-src', [Turnstile::HOST]);
        }

        return implode('; ', array_map(
            fn (string $directive, array $sources): string => sprintf('%s %s', $directive, implode(' ', $sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * @param  array<string, list<string>>  $directives
     * @param  list<string>  $sources
     * @return array<string, list<string>>
     */
    private function allow(array $directives, string $directive, array $sources): array
    {
        $directives[$directive] = array_values(array_unique([
            ...array_diff($directives[$directive] ?? [], ["'none'"]),
            ...$sources,
        ]));

        return $directives;
    }

    private function showsTurnstile(Response $response): bool
    {
        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, sprintf('<script src="%s"', Turnstile::SCRIPT_URL))) {
            return false;
        }

        return $this->turnstile->enabled();
    }

    /**
     * @return list<string>
     */
    private function embedHosts(Response $response): array
    {
        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, '<iframe')) {
            return [];
        }

        $allowed = [];

        foreach ((array) config('cms.embeds', []) as $embed) {
            foreach ((array) ($embed['hosts'] ?? []) as $host) {
                $allowed[] = Str::lower((string) $host);
            }
        }

        preg_match_all('~<iframe\b[^>]*\bsrc="https://([^/"?#]+)~i', $content, $matches);

        $used = array_map(fn (string $host): string => Str::lower(html_entity_decode($host)), $matches[1]);

        return array_values(array_unique(array_intersect($used, $allowed)));
    }
}
