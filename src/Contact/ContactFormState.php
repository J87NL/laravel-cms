<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;

final readonly class ContactFormState
{
    public const string SENT_QUERY = 'verzonden';

    private const string ATTRIBUTE = 'cms.contact.state';

    /**
     * @param  array<string, string>  $old
     */
    public function __construct(
        public int $blockId,
        public MessageBag $errors,
        public array $old,
    ) {}

    public static function remember(Request $request, self $state): void
    {
        $request->attributes->set(self::ATTRIBUTE, $state);
    }

    public static function for(Request $request, int $blockId): ?self
    {
        $state = $request->attributes->get(self::ATTRIBUTE);

        return $state instanceof self && $state->blockId === $blockId ? $state : null;
    }

    public static function sentQuery(): string
    {
        $query = config('cms.contact.sent_query');

        return is_string($query) && $query !== '' ? $query : self::SENT_QUERY;
    }

    public static function sent(Request $request, int $blockId): bool
    {
        return (string) $request->query(self::sentQuery(), '') === (string) $blockId;
    }
}
