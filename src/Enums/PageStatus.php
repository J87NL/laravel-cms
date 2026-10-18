<?php

declare(strict_types=1);

namespace J87NL\Cms\Enums;

enum PageStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return __(sprintf('cms::pages.statuses.%s', $this->value));
    }
}
