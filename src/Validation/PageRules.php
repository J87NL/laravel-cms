<?php

declare(strict_types=1);

namespace J87NL\Cms\Validation;

use Illuminate\Validation\Rule;
use J87NL\Cms\Media\MediaOptions;
use J87NL\Cms\Models\Page;

class PageRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function fields(): array
    {
        return [
            'title' => ['string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', Rule::exists((new Page)->getTable(), 'id')],
            'menu_title' => ['nullable', 'string', 'max:80'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'og_image_id' => ['nullable', 'integer', MediaOptions::existsRule()],
            'canonical_url' => ['nullable', 'url:https,http', 'max:255'],
            'noindex' => ['boolean'],
            'schema_type' => ['nullable', 'string', Rule::in(self::schemaTypes())],
        ];
    }

    /**
     * @return list<string>
     */
    public static function schemaTypes(): array
    {
        $types = config('cms.schema_types', []);

        return is_array($types) ? array_values(array_map('strval', $types)) : [];
    }

    /**
     * @return list<mixed>
     */
    public static function for(string $field): array
    {
        return self::fields()[$field] ?? [];
    }
}
