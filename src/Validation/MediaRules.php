<?php

declare(strict_types=1);

namespace J87NL\Cms\Validation;

class MediaRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function fields(): array
    {
        return [
            'alt' => ['required', 'string', 'max:250'],
            'caption' => ['nullable', 'string', 'max:500'],
            'focal_x' => ['integer', 'min:0', 'max:100'],
            'focal_y' => ['integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function for(string $field): array
    {
        return self::fields()[$field] ?? [];
    }
}
