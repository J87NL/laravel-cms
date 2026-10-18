<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

use TranquilTools\FormBuilder\Fields\BaseField;

interface BlockType
{
    public function key(): string;

    public function label(): string;

    /**
     * @return list<BaseField>
     */
    public function fields(): array;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data): array;

    /**
     * @return view-string
     */
    public function view(): string;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function viewData(array $data, BlockContext $context): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function toPlainText(array $data): string;
}
