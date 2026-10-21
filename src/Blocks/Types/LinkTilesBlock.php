<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use Illuminate\Validation\Rule;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Media\MediaOptions;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Rules\SafeLink;
use TranquilTools\FormBuilder\Fields\Radio;
use TranquilTools\FormBuilder\Fields\Repeater;
use TranquilTools\FormBuilder\Fields\Select;
use TranquilTools\FormBuilder\Fields\Text;

class LinkTilesBlock extends AbstractBlockType implements ReferencesMedia
{
    public const string DEFAULT_REL = 'noopener';

    /**
     * @var array<string, string>
     */
    public const array RELS = [
        'noopener' => 'plain',
        'me noopener' => 'me',
        'sponsored noopener' => 'sponsored',
    ];

    public function key(): string
    {
        return 'link_tiles';
    }

    public function view(): string
    {
        return 'cms::blocks.link-tiles';
    }

    public function fields(): array
    {
        return [
            Text::make('title')
                ->label(__('cms::blocks.link_tiles.fields.title'))
                ->rules([
                    'nullable',
                    'string',
                    'max:160',
                ]),

            Radio::make('rel')
                ->label(__('cms::blocks.link_tiles.fields.rel'))
                ->help(__('cms::blocks.link_tiles.help.rel'))
                ->options(array_map(
                    fn (string $rel, string $key): array => [
                        'value' => $rel,
                        'label' => __(sprintf('cms::blocks.link_tiles.rels.%s', $key)),
                    ],
                    array_keys(self::RELS),
                    self::RELS,
                ))
                ->optionLabel('label')
                ->optionValue('value')
                ->default(self::DEFAULT_REL)
                ->rules([
                    'nullable',
                    Rule::in(array_keys(self::RELS)),
                ]),

            Repeater::make('tiles')
                ->label(__('cms::blocks.link_tiles.fields.tiles'))
                ->addActionLabel(__('cms::blocks.link_tiles.add_tile'))
                ->itemLabel(__('cms::blocks.link_tiles.tile'))
                ->reorderable()
                ->min(1)
                ->required()
                ->rules([
                    'min:1',
                    'max:100',
                ])
                ->fields([
                    Text::make('name')
                        ->label(__('cms::blocks.link_tiles.fields.name'))
                        ->help(__('cms::blocks.link_tiles.help.name'))
                        ->required()
                        ->rules([
                            'string',
                            'max:160',
                        ]),

                    Select::make('media_id')
                        ->label(__('cms::blocks.link_tiles.fields.media_id'))
                        ->options(MediaOptions::all())
                        ->optionLabel('label')
                        ->optionValue('value')
                        ->searchable()
                        ->rules([
                            'nullable',
                            'integer',
                            MediaOptions::existsRule(),
                        ]),

                    Text::make('url')
                        ->label(__('cms::blocks.link_tiles.fields.url'))
                        ->help(__('cms::blocks.link_tiles.help.url'))
                        ->rules([
                            'nullable',
                            'string',
                            'max:2048',
                            'starts_with:https://',
                            new SafeLink,
                        ]),
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'title' => trim($this->string($data, 'title')),
            'rel' => $this->rel($data),
            'tiles' => $this->tiles($data),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'title' => trim($this->string($data, 'title')),
            'headingId' => sprintf('cms-link-tiles-%d', $context->position),
            'rel' => $this->rel($data),
            'lazy' => ! $context->isFirst(),
            'tiles' => array_map(
                fn (array $tile): array => [
                    'name' => $tile['name'],
                    'url' => $tile['url'],
                    'asset' => $this->image($context->asset($tile['media_id'])),
                ],
                $this->tiles($data),
            ),
        ];
    }

    public function toPlainText(array $data): string
    {
        return trim(implode(' ', [
            trim($this->string($data, 'title')),
            implode(', ', array_column($this->tiles($data), 'name')),
        ]));
    }

    public function mediaIds(array $data): array
    {
        return array_values(array_unique(array_filter(
            array_column($this->tiles($data), 'media_id'),
            fn (?int $id): bool => ! is_null($id),
        )));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rel(array $data): string
    {
        $rel = $this->string($data, 'rel');

        if (! array_key_exists($rel, self::RELS)) {
            return self::DEFAULT_REL;
        }

        return $rel;
    }

    private function safeUrl(string $url): ?string
    {
        if (! str_starts_with($url, 'https://') || ! SafeLink::passes($url)) {
            return null;
        }

        return $url;
    }

    private function image(?MediaAsset $asset): ?MediaAsset
    {
        if (is_null($asset) || $asset->sources() === []) {
            return null;
        }

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, media_id: int|null, url: string|null}>
     */
    private function tiles(array $data): array
    {
        $tiles = $data['tiles'] ?? [];

        if (! is_array($tiles)) {
            return [];
        }

        $normalized = [];

        foreach ($tiles as $tile) {
            if (! is_array($tile)) {
                continue;
            }

            $name = trim($this->string($tile, 'name'));

            if ($name === '') {
                continue;
            }

            $mediaId = $this->string($tile, 'media_id');
            $url = trim($this->string($tile, 'url'));

            $normalized[] = [
                'name' => $name,
                'media_id' => is_numeric($mediaId) && (int) $mediaId > 0 ? (int) $mediaId : null,
                'url' => $this->safeUrl($url),
            ];
        }

        return $normalized;
    }
}
