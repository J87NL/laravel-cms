<?php

declare(strict_types=1);

namespace J87NL\Cms\Rendering;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\BlockType;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;

class BlockRenderer
{
    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Factory $views,
    ) {}

    /**
     * @return list<HtmlString>
     */
    public function render(Page $page): array
    {
        $blocks = Block::query()
            ->where('page_id', $page->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $renderable = [];
        $mediaIds = [];

        foreach ($blocks as $block) {
            $type = $this->registry->get($block->type);

            if (is_null($type)) {
                continue;
            }

            $renderable[] = [
                'block' => $block,
                'type' => $type,
            ];

            if ($type instanceof ReferencesMedia) {
                $mediaIds = [
                    ...$mediaIds,
                    ...$type->mediaIds($block->data),
                ];
            }
        }

        $media = $this->media($mediaIds);

        $rendered = [];

        foreach ($renderable as $position => $item) {
            $rendered[] = $this->renderBlock($item['block'], $item['type'], new BlockContext($page, $position, $media));
        }

        return $rendered;
    }

    private function renderBlock(Block $block, BlockType $type, BlockContext $context): HtmlString
    {
        $html = $this->views->make($type->view(), [
            ...$type->viewData($block->data, $context),
            'block' => $block,
            'context' => $context,
        ])->render();

        return new HtmlString($html);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, MediaAsset>
     */
    private function media(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return MediaAsset::query()
            ->with('media')
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');
    }
}
