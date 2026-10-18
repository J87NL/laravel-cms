<?php

declare(strict_types=1);

namespace J87NL\Cms\Media;

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;

class MediaUsage
{
    public function __construct(
        private readonly BlockRegistry $registry,
    ) {}

    /**
     * @return array<int, int>
     */
    public function counts(): array
    {
        $counts = [];
        $types = array_filter(
            $this->registry->registered(),
            fn (mixed $type): bool => $type instanceof ReferencesMedia,
        );

        if ($types !== []) {
            Block::query()
                ->whereIn('type', array_keys($types))
                ->select([
                    'id',
                    'type',
                    'data',
                ])
                ->lazyById(200)
                ->each(function (Block $block) use ($types, &$counts): void {
                    foreach (array_unique($types[$block->type]->mediaIds($block->data)) as $id) {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                });
        }

        $openGraphIds = Page::query()
            ->whereNotNull('og_image_id')
            ->pluck('og_image_id');

        foreach ($openGraphIds as $id) {
            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
        }

        return $counts;
    }

    public function count(MediaAsset $asset): int
    {
        return $this->counts()[$asset->id] ?? 0;
    }
}
