<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
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
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', sprintf('max-age=%d', (int) config('cms.security_headers.hsts_max_age', 31536000)));
        }

        return $response;
    }

    private function policy(Response $response): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
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
            $directives[(string) $directive] = array_values(array_unique([
                ...array_diff($directives[(string) $directive] ?? [], ["'none'"]),
                ...array_map('strval', (array) $sources),
            ]));
        }

        $embedHosts = $this->embedHosts($response);

        if ($embedHosts !== []) {
            $directives['frame-src'] = array_map(fn (string $host): string => sprintf('https://%s', $host), $embedHosts);
        }

        return implode('; ', array_map(
            fn (string $directive, array $sources): string => sprintf('%s %s', $directive, implode(' ', $sources)),
            array_keys($directives),
            $directives,
        ));
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

        $hosts = [];

        foreach ((array) config('cms.embeds', []) as $embed) {
            foreach ((array) ($embed['hosts'] ?? []) as $host) {
                $hosts[] = (string) $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
