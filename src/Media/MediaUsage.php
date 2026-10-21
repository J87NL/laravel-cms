<?php

declare(strict_types=1);

namespace J87NL\Cms\Media;

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\ReferencesMedia;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Models\SiteSettings;

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

        $pendingImageIds = [];

        Revision::query()
            ->pending()
            ->select([
                'id',
                'snapshot',
            ])
            ->lazyById(200)
            ->each(function (Revision $revision) use ($types, &$counts, &$pendingImageIds): void {
                foreach ($revision->snapshot['blocks'] as $block) {
                    $type = $types[(string) ($block['type'] ?? '')] ?? null;
                    $data = $block['data'] ?? null;

                    if (is_null($type) || ! is_array($data)) {
                        continue;
                    }

                    foreach (array_unique($type->mediaIds($data)) as $id) {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }

                $pendingImageIds[] = $revision->snapshot['page']['og_image_id'] ?? null;
            });

        $settings = SiteSettings::current();

        $referencedIds = [
            ...Page::query()
                ->whereNotNull('og_image_id')
                ->pluck('og_image_id')
                ->all(),
            ...$pendingImageIds,
            $settings->og_image_id,
            $settings->entity_image_id,
        ];

        foreach (array_filter($referencedIds, fn (mixed $id): bool => is_numeric($id)) as $id) {
            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
        }

        return $counts;
    }

    public function count(MediaAsset $asset): int
    {
        return $this->counts()[$asset->id] ?? 0;
    }
}
