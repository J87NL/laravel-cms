<?php

declare(strict_types=1);

namespace J87NL\Cms\Enums;

enum EditMode: string
{
    case Draft = 'draft';
    case Live = 'live';
}
