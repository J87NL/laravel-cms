<?php

declare(strict_types=1);

namespace J87NL\Cms\Validation;

use Illuminate\Validation\Rule;
use J87NL\Cms\Models\Redirect;

class RedirectRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function fields(): array
    {
        return [
            'from_path' => ['string', 'max:255'],
            'to' => ['string', 'max:2048', 'regex:#^(/|https?://)#i'],
            'status_code' => [Rule::in(array_map('strval', Redirect::STATUS_CODES))],
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
