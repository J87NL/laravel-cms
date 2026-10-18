<?php

declare(strict_types=1);

namespace J87NL\Cms\Pages;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class HomePageLock
{
    private const string KEY = 'cms:home-page';

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback, string $errorKey = 'page'): mixed
    {
        try {
            return Cache::lock(self::KEY, 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                $errorKey => __('cms::validation.busy'),
            ]);
        }
    }
}
