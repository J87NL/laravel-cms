<?php

declare(strict_types=1);

namespace J87NL\Cms\Cache;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PageCache
{
    private const string VERSION_KEY = 'cms:page-cache:version';

    /**
     * @var list<string>
     */
    private const array KEPT_HEADERS = [
        'Content-Type',
        'X-Robots-Tag',
    ];

    public function __construct(
        private readonly CacheFactory $cache,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('cms.cache.enabled', true);
    }

    public function cacheable(Request $request): bool
    {
        if (! $this->enabled() || ! $request->isMethodCacheable() || $request->getQueryString() !== null) {
            return false;
        }

        return ! $request->cookies->has((string) config('session.cookie'));
    }

    public function get(Request $request): ?Response
    {
        $cached = $this->store()->get($this->key($request));

        if (! is_array($cached) || ! is_string($cached['content'] ?? null) || ! is_array($cached['headers'] ?? null)) {
            return null;
        }

        return new Response($cached['content'], (int) ($cached['status'] ?? 200), $cached['headers']);
    }

    public function put(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 200 || $response->headers->getCookies() !== [] || $response->headers->hasCacheControlDirective('no-store')) {
            return false;
        }

        $content = $response->getContent();

        if (! is_string($content)) {
            return false;
        }

        $headers = [];

        foreach (self::KEPT_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        $this->store()->put($this->key($request), [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'content' => $content,
        ], (int) config('cms.cache.ttl', 86400));

        return true;
    }

    public function flush(): void
    {
        $this->store()->forever(self::VERSION_KEY, $this->version() + 1);
    }

    public function version(): int
    {
        $version = $this->store()->get(self::VERSION_KEY);

        return is_numeric($version) ? (int) $version : 1;
    }

    private function key(Request $request): string
    {
        return sprintf('cms:page-cache:%d:%s', $this->version(), sha1(sprintf('%s|%s', $request->getHost(), $request->getPathInfo())));
    }

    private function store(): Repository
    {
        $store = config('cms.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }
}
