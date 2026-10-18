<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

use TranquilTools\FormBuilder\Fields\Wysiwyg;

class RichTextField
{
    public static function make(string $name, string $label): Wysiwyg
    {
        return Wysiwyg::make($name)
            ->label($label)
            ->editor((string) config('cms.rich_text.editor', 'hugerte'))
            ->options(self::options())
            ->rules([
                'string',
                'max:100000',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function options(): array
    {
        $options = config('cms.rich_text.editor_options', []);
        $options = is_array($options) ? $options : [];
        $languages = config('cms.rich_text.languages', []);
        $locale = app()->getLocale();

        if (! is_array($languages) || ! in_array($locale, $languages, true)) {
            return $options;
        }

        return [
            ...$options,
            'language' => $locale,
        ];
    }
}
