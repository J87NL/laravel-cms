<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

class ContactFormLimiter
{
    private const int DECAY_SECONDS = 3600;

    private const string GLOBAL_KEY = 'cms-contact:global';

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    public function tooManyFromAddress(Request $request): bool
    {
        return $this->limiter->tooManyAttempts($this->addressKey($request), (int) config('cms.contact.per_hour', 5));
    }

    public function hitAddress(Request $request): void
    {
        $this->limiter->hit($this->addressKey($request), self::DECAY_SECONDS);
    }

    public function claimOverall(): bool
    {
        return $this->limiter->hit(self::GLOBAL_KEY, self::DECAY_SECONDS) <= (int) config('cms.contact.global_per_hour', 30);
    }

    public function retryAfterForAddress(Request $request): int
    {
        return max(1, $this->limiter->availableIn($this->addressKey($request)));
    }

    public function retryAfterOverall(): int
    {
        return max(1, $this->limiter->availableIn(self::GLOBAL_KEY));
    }

    private function addressKey(Request $request): string
    {
        return sprintf('cms-contact:address:%s', sha1((string) $request->ip()));
    }
}
