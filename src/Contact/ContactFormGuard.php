<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Http\Request;

class ContactFormGuard
{
    public const string HONEYPOT = 'website';

    public const string TIMESTAMP = 'form_token';

    public function token(int $blockId): string
    {
        $time = now()->getTimestamp();

        return sprintf('%d.%s', $time, $this->signature($blockId, $time));
    }

    public function looksHuman(Request $request, int $blockId): bool
    {
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            return false;
        }

        $parts = explode('.', (string) $request->input(self::TIMESTAMP, ''), 2);

        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            return false;
        }

        $time = (int) $parts[0];

        if (! hash_equals($this->signature($blockId, $time), $parts[1])) {
            return false;
        }

        $age = now()->getTimestamp() - $time;

        return $age >= (int) config('cms.contact.min_seconds', 3) && $age <= (int) config('cms.contact.max_seconds', 172800);
    }

    private function signature(int $blockId, int $time): string
    {
        return hash_hmac('sha256', sprintf('cms-contact|%d|%d', $blockId, $time), (string) config('app.key'));
    }
}
