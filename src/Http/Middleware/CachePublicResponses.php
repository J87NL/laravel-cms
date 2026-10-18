<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use J87NL\Cms\Cache\PageCache;
use Symfony\Component\HttpFoundation\Response;

class CachePublicResponses
{
    public const string HEADER = 'X-Cms-Cache';

    public function __construct(
        private readonly PageCache $cache,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cache->cacheable($request)) {
            $response = $next($request);

            if ($this->cache->enabled()) {
                $response->headers->set(self::HEADER, 'bypass');
            }

            return $response;
        }

        $cached = $this->cache->get($request);

        if (! is_null($cached)) {
            $cached->headers->set(self::HEADER, 'hit');

            return $cached;
        }

        $response = $next($request);

        if ($this->cache->put($request, $response)) {
            $response->headers->set(self::HEADER, 'miss');
        }

        return $response;
    }
}
