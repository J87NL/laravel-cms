<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Rules\SafeLink;
use TranquilTools\FormBuilder\Fields\Text;
use TranquilTools\FormBuilder\Fields\Textarea;

class CallToActionBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'call_to_action';
    }

    public function view(): string
    {
        return 'cms::blocks.call-to-action';
    }

    public function fields(): array
    {
        return [
            Text::make('title')
                ->label(__('cms::blocks.call_to_action.fields.title'))
                ->required()
                ->rules([
                    'string',
                    'max:160',
                ]),

            Textarea::make('text')
                ->label(__('cms::blocks.call_to_action.fields.text'))
                ->rules([
                    'nullable',
                    'string',
                    'max:1000',
                ]),

            Text::make('button_label')
                ->label(__('cms::blocks.call_to_action.fields.button_label'))
                ->required()
                ->rules([
                    'string',
                    'max:80',
                ]),

            Text::make('url')
                ->label(__('cms::blocks.call_to_action.fields.url'))
                ->help(__('cms::blocks.call_to_action.help.url'))
                ->required()
                ->rules([
                    'string',
                    'max:2048',
                    new SafeLink,
                ]),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'title' => trim($this->string($data, 'title')),
            'text' => trim($this->string($data, 'text')),
            'button_label' => trim($this->string($data, 'button_label')),
            'url' => trim($this->string($data, 'url')),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        $url = $this->string($data, 'url');

        return [
            'title' => $this->string($data, 'title'),
            'text' => $this->string($data, 'text'),
            'buttonLabel' => $this->string($data, 'button_label'),
            'url' => SafeLink::passes($url) ? $url : null,
        ];
    }

    public function toPlainText(array $data): string
    {
        return trim(sprintf('%s %s', $this->string($data, 'title'), $this->string($data, 'text')));
    }
}
