<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Turnstile
{
    public const string HOST = 'https://challenges.cloudflare.com';

    public const string SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const string RESPONSE_FIELD = 'cf-turnstile-response';

    public const int MAX_TOKEN_LENGTH = 2048;

    public function __construct(
        private readonly TurnstileKeys $keys,
    ) {}

    public function enabled(): bool
    {
        return trim((string) $this->keys->siteKey()) !== '' && trim((string) $this->keys->secretKey()) !== '';
    }

    public function siteKey(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return trim((string) $this->keys->siteKey());
    }

    public function passes(Request $request): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $token = trim((string) $request->input(self::RESPONSE_FIELD, ''));

        if ($token === '' || mb_strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $timeout = (float) config('cms.contact.turnstile.timeout', 3);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->post(self::VERIFY_URL, [
                    'secret' => trim((string) $this->keys->secretKey()),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (HttpClientException $e) {
            $this->warn(['error' => $e::class]);

            return true;
        }

        $success = $response->json('success');

        if ($response->failed() || ! is_bool($success)) {
            $this->warn(['status' => $response->status()]);

            return true;
        }

        $errors = array_values(array_filter((array) $response->json('error-codes', []), is_string(...)));

        if (array_intersect($errors, ['missing-input-secret', 'invalid-input-secret']) !== []) {
            Log::warning('Cloudflare Turnstile refused the secret key of the contact form, so the token was accepted unchecked.', [
                'errors' => implode(',', $errors),
            ]);

            return true;
        }

        return $success;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function warn(array $context): void
    {
        Log::warning('The contact form could not reach Cloudflare Turnstile, so the token was accepted unchecked.', $context);
    }
}
