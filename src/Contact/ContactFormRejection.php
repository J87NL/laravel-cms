<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

enum ContactFormRejection: string
{
    case BotCheck = 'bot_check';
    case InvalidEncoding = 'invalid_encoding';
    case OnlyLinks = 'only_links';
    case TooManyLinks = 'too_many_links';
    case BlockedKeyword = 'blocked_keyword';
    case Rspamd = 'rspamd';
}
