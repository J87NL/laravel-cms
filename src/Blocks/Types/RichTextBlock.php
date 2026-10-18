<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks\Types;

use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\RichTextField;
use J87NL\Cms\Html\RichTextSanitizer;

class RichTextBlock extends AbstractBlockType
{
    public function __construct(
        private readonly RichTextSanitizer $sanitizer,
    ) {}

    public function key(): string
    {
        return 'rich_text';
    }

    public function view(): string
    {
        return 'cms::blocks.rich-text';
    }

    public function fields(): array
    {
        return [
            RichTextField::make('html', __('cms::blocks.rich_text.fields.html'))->required(),
        ];
    }

    public function prepare(array $data): array
    {
        return [
            'html' => $this->sanitizer->sanitize($this->string($data, 'html')),
        ];
    }

    public function viewData(array $data, BlockContext $context): array
    {
        return [
            'html' => $this->sanitizer->sanitize($this->string($data, 'html')),
        ];
    }

    public function toPlainText(array $data): string
    {
        return $this->plain($this->string($data, 'html'));
    }
}
