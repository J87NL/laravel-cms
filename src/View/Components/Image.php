<?php

declare(strict_types=1);

namespace J87NL\Cms\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use J87NL\Cms\Models\MediaAsset;

class Image extends Component
{
    public function __construct(
        public MediaAsset $asset,
        public string $sizes = '100vw',
        public bool $lazy = true,
        public bool $cover = false,
        public ?string $alt = null,
    ) {}

    public function render(): View
    {
        $sources = $this->asset->sources();
        $largest = end($sources);

        return view('cms::components.image', [
            'src' => $largest === false ? null : $largest['url'],
            'srcset' => $this->srcset($sources),
            'width' => $this->asset->width(),
            'height' => $this->asset->height(),
        ]);
    }

    /**
     * @param  list<array{url: string, width: int}>  $sources
     */
    private function srcset(array $sources): ?string
    {
        $candidates = array_filter($sources, fn (array $source): bool => $source['width'] > 0);

        if (count($candidates) < 2) {
            return null;
        }

        return implode(', ', array_map(
            fn (array $source): string => sprintf('%s %dw', $source['url'], $source['width']),
            $candidates,
        ));
    }
}
