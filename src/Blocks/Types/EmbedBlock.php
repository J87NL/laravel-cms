<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Rules\AllowedEmbedUrl;
use TranquilTools\FormBuilder\Fields\Text;

class EmbedBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'embed';
    }

    public function view(): string
    {
        return 'cms::blocks.embed';
    }

    public function fields(): array
    {
        return [
            Text::make('url')
                ->label(__('cms::blocks.embed.fields.url'))
                ->help(__('cms::blocks.embed.help.url'))
                ->required()
                ->rules([
                    'string',
                    'max:2048',
                    new AllowedEmbedUrl,
                ]),

            Text::make('title')
                ->label(__('cms::blocks.embed.fields.title'))
                ->help(__('cms::blocks.embed.help.title'))
                ->required()
                ->rules([
                    'string',
                    'max:160',
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'url' => trim($this->string($data, 'url')),
            'title' => trim($this->string($data, 'title')),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        $url = trim($this->string($data, 'url'));

        return [
            'url' => AllowedEmbedUrl::passes($url) ? $url : null,
            'title' => $this->string($data, 'title'),
        ];
    }

    public function toPlainText(array $data): string
    {
        return $this->string($data, 'title');
    }
}
