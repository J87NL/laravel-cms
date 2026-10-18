<?php

declare(strict_types=1);

namespace J87NL\Cms\Enums;

enum RedirectSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Import = 'import';

    public function label(): string
    {
        return __(sprintf('cms::redirects.sources.%s', $this->value));
    }
}
