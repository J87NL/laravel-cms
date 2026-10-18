<?php

declare(strict_types=1);

namespace J87NL\Cms\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Rendering\BlockRenderer;

class Blocks extends Component
{
    public function __construct(
        public Page $page,
        private readonly BlockRenderer $renderer,
    ) {}

    public function render(): View
    {
        return view('cms::components.blocks', [
            'rendered' => $this->renderer->render($this->page),
        ]);
    }
}
