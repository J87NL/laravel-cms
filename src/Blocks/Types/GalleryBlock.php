<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Media\MediaOptions;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\FormConfig;

class GalleryBlock extends AbstractBlockType implements ReferencesMedia
{
    public function key(): string
    {
        return 'gallery';
    }

    public function view(): string
    {
        return 'cms::blocks.gallery';
    }

    public function fields(): array
    {
        return [
            Select::make('media_ids')
                ->label(__('cms::blocks.gallery.fields.media_ids'))
                ->options(MediaOptions::all())
                ->optionLabel('label')
                ->optionValue('value')
                ->searchable()
                ->multiple()
                ->required()
                ->rules([
                    'array',
                    'min:1',
                    'max:60',
                ]),
        ];
    }

    public function rules(): array
    {
        return [
            ...FormConfig::make($this->fields())->getRules(),
            'media_ids.*' => [
                'integer',
                'distinct',
                MediaOptions::existsRule(),
            ],
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'media_ids' => $this->mediaIds($data),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'assets' => $context->assets($this->mediaIds($data)),
            'lazy' => ! $context->isFirst(),
        ];
    }

    public function toPlainText(array $data): string
    {
        return '';
    }

    public function mediaIds(array $data): array
    {
        $ids = $data['media_ids'] ?? [];

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids),
            fn (int $id): bool => $id > 0,
        )));
    }
}
