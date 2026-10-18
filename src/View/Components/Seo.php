<?php

declare(strict_types=1);

namespace J87NL\Cms\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Seo\PageSeo;

class Seo extends Component
{
    public function __construct(
        public Page $page,
        private readonly PageSeo $seo,
        public bool $preview = false,
    ) {}

    public function render(): View
    {
        return view('cms::components.seo', [
            'seo' => $this->seo->forPage($this->page, $this->preview),
        ]);
    }
}
