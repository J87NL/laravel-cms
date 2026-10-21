<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

use TranquilTools\FormBuilder\FormConfig;

abstract class AbstractBlockType implements BlockType
{
    public function label(): string
    {
        $key = sprintf('cms::blocks.%s.label', $this->key());
        $label = __($key);

        return is_string($label) ? $label : $key;
    }

    public function rules(): array
    {
        return FormConfig::make($this->fields())->getRules();
    }

    public function prepare(array $data): array
    {
        return $data;
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    protected function plain(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
