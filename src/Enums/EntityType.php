<?php

declare(strict_types=1);

namespace J87NL\Cms\Enums;

enum EntityType: string
{
    case Person = 'Person';
    case Organization = 'Organization';

    public function label(): string
    {
        return __(sprintf('cms::settings.entity_types.%s', $this->value));
    }
}
