<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class ContactFormLimiter
{
    public const string NAMED_LIMITER = 'cms-contact';

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

    public function secondsUntilSendAllowed(Request $request): ?int
    {
        foreach ($this->namedLimits($request) as $limit) {
            if ($this->limiter->tooManyAttempts($this->namedKey($limit), $limit->maxAttempts)) {
                return max(1, $this->limiter->availableIn($this->namedKey($limit)));
            }
        }

        return null;
    }

    public function hitSend(Request $request): void
    {
        foreach ($this->namedLimits($request) as $limit) {
            $this->limiter->hit($this->namedKey($limit), $limit->decaySeconds);
        }
    }

    /**
     * @return list<Limit>
     */
    public static function defaultLimits(Request $request): array
    {
        $perMinute = config('cms.contact.per_minute', 3);

        if (! is_numeric($perMinute) || (int) $perMinute < 1) {
            return [];
        }

        return [
            Limit::perMinute((int) $perMinute)->by(sprintf('%s|%s', (string) $request->ip(), (string) $request->route('block'))),
        ];
    }

    /**
     * @return list<Limit>
     */
    private function namedLimits(Request $request): array
    {
        $callback = $this->limiter->limiter(self::NAMED_LIMITER);

        if (is_null($callback)) {
            return [];
        }

        $limits = $callback($request);

        if ($limits instanceof Limit) {
            return [$limits];
        }

        if (! is_array($limits)) {
            return [];
        }

        return array_values(array_filter($limits, fn (mixed $limit): bool => $limit instanceof Limit));
    }

    private function namedKey(Limit $limit): string
    {
        return sprintf('cms-contact:send:%s', md5(sprintf('%s|%s', self::NAMED_LIMITER, $limit->key ?: $limit->fallbackKey())));
    }

    private function addressKey(Request $request): string
    {
        return sprintf('cms-contact:address:%s', sha1((string) $request->ip()));
    }
}
