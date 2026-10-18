<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use Illuminate\Validation\Rule;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Blocks\RichTextField;
use J87NL\Cms\Html\RichTextSanitizer;
use J87NL\Cms\Media\MediaOptions;
use TranquilTools\FormBuilder\Fields\Radio;
use TranquilTools\FormBuilder\Fields\Select;

class TextWithImageBlock extends AbstractBlockType implements ReferencesMedia
{
    /**
     * @var list<string>
     */
    private const array POSITIONS = [
        'left',
        'right',
    ];

    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    public function key(): string
    {
        return 'text_image';
    }

    public function view(): string
    {
        return 'cms::blocks.text-image';
    }

    public function fields(): array
    {
        return [
            RichTextField::make('html', __('cms::blocks.text_image.fields.html'))->required(),

            Select::make('media_id')
                ->label(__('cms::blocks.text_image.fields.media_id'))
                ->options(MediaOptions::all())
                ->optionLabel('label')
                ->optionValue('value')
                ->searchable()
                ->required()
                ->rules([
                    'integer',
                    MediaOptions::existsRule(),
                ]),

            Radio::make('image_position')
                ->label(__('cms::blocks.text_image.fields.image_position'))
                ->options(array_map(
                    fn (string $position): array => [
                        'value' => $position,
                        'label' => __(sprintf('cms::blocks.text_image.positions.%s', $position)),
                    ],
                    self::POSITIONS,
                ))
                ->optionLabel('label')
                ->optionValue('value')
                ->inline()
                ->default('left')
                ->required()
                ->rules([
                    Rule::in(self::POSITIONS),
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'html' => $this->sanitizer->sanitize($this->string($data, 'html')),
            'media_id' => (int) $this->string($data, 'media_id'),
            'image_position' => $this->position($data),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'html' => $this->sanitizer->sanitize($this->string($data, 'html')),
            'asset' => $context->asset($data['media_id'] ?? null),
            'position' => $this->position($data),
            'lazy' => ! $context->isFirst(),
        ];
    }

    public function toPlainText(array $data): string
    {
        return $this->plain($this->string($data, 'html'));
    }

    public function mediaIds(array $data): array
    {
        $id = (int) $this->string($data, 'media_id');

        return $id > 0 ? [$id] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function position(array $data): string
    {
        $position = $this->string($data, 'image_position');

        return in_array($position, self::POSITIONS, true) ? $position : 'left';
    }
}
