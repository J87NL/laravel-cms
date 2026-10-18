<?php

declare(strict_types=1);

namespace J87NL\Cms\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use J87NL\Cms\Rendering\MenuBuilder;

class Menu extends Component
{
    public const string HIDDEN_ATTRIBUTE = 'cms.hide_menus';

    public function __construct(
        public string $key,
        private readonly MenuBuilder $builder,
        private readonly Request $request,
    ) {}

    public function render(): View
    {
        $items = $this->request->attributes->getBoolean(self::HIDDEN_ATTRIBUTE)
            ? []
            : $this->builder->tree($this->key, $this->request->path());

        return view('cms::components.menu', [
            'items' => $items,
        ]);
    }
}
