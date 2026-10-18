<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Media\MediaOptions;
use TranquilTools\FormBuilder\Fields\Select;

class ImageBlock extends AbstractBlockType implements ReferencesMedia
{
    public function key(): string
    {
        return 'image';
    }

    public function view(): string
    {
        return 'cms::blocks.image';
    }

    public function fields(): array
    {
        return [
            Select::make('media_id')
                ->label(__('cms::blocks.image.fields.media_id'))
                ->options(MediaOptions::all())
                ->optionLabel('label')
                ->optionValue('value')
                ->searchable()
                ->required()
                ->rules([
                    'integer',
                    MediaOptions::existsRule(),
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'media_id' => (int) $this->string($data, 'media_id'),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'asset' => $context->asset($data['media_id'] ?? null),
            'lazy' => ! $context->isFirst(),
        ];
    }

    public function toPlainText(array $data): string
    {
        return '';
    }

    public function mediaIds(array $data): array
    {
        $id = (int) $this->string($data, 'media_id');

        return $id > 0 ? [$id] : [];
    }
}
