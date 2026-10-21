<?php

declare(strict_types=1);

namespace J87NL\Cms\Contact;

enum ContactFormVerdict
{
    case Human;
    case Bot;
    case Expired;
}
