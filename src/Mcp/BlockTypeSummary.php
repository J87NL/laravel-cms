<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\BlockType;

class BlockTypeSummary
{
    private ?string $summary = null;

    public function __construct(
        private readonly BlockRegistry $registry,
    ) {}

    public function text(): string
    {
        return $this->summary ??= sprintf('Block types with their label and data fields (* = required): %s.', implode('; ', array_map(
            fn (BlockType $type): string => sprintf('%s "%s" (%s)', $type->key(), $this->label($type), implode(', ', $this->fieldNames($type))),
            $this->registry->all(),
        )));
    }

    private function label(BlockType $type): string
    {
        $label = __($type->label());

        return is_string($label) ? $label : $type->label();
    }

    /**
     * @return list<string>
     */
    private function fieldNames(BlockType $type): array
    {
        $fields = [];
        $children = [];

        foreach ($type->rules() as $field => $rules) {
            $field = (string) $field;
            $list = is_string($rules) ? explode('|', $rules) : (is_array($rules) ? $rules : [$rules]);
            $required = in_array('required', $list, true);

            if (preg_match('/^([^.]+)\.\*\.([^.]+)$/', $field, $matches) === 1) {
                $children[$matches[1]][] = $required ? sprintf('%s*', $matches[2]) : $matches[2];

                continue;
            }

            if (str_contains($field, '.')) {
                continue;
            }

            $fields[$field] = $required ? sprintf('%s*', $field) : $field;
        }

        foreach ($children as $parent => $names) {
            if (! array_key_exists($parent, $fields)) {
                continue;
            }

            $fields[$parent] = sprintf('%s: list of {%s}', $fields[$parent], implode(', ', $names));
        }

        return array_values($fields);
    }
}
