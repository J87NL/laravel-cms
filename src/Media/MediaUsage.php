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
use J87NL\Cms\Pages\PendingRevisionEditor;

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
        $liveIds = [];
        $types = array_filter(
            $this->registry->registered(),
            fn (mixed $type): bool => $type instanceof ReferencesMedia,
        );

        if ($types !== []) {
            Block::query()
                ->whereIn('type', array_keys($types))
                ->select([
                    'id',
                    'page_id',
                    'type',
                    'data',
                ])
                ->lazyById(200)
                ->each(function (Block $block) use ($types, &$counts, &$liveIds): void {
                    foreach (array_unique($types[$block->type]->mediaIds($block->data)) as $id) {
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                        $liveIds[$block->page_id][$id] = true;
                    }
                });
        }

        $pageImageIds = array_map('intval', Page::query()
            ->whereNotNull('og_image_id')
            ->pluck('og_image_id', 'id')
            ->all());

        Revision::query()
            ->pending()
            ->select([
                'id',
                'page_id',
                'snapshot',
            ])
            ->lazyById(200)
            ->each(function (Revision $revision) use ($types, $pageImageIds, &$counts, &$liveIds): void {
                $content = PendingRevisionEditor::content($revision->snapshot);
                $pendingIds = [];

                foreach ($content['blocks'] as $block) {
                    $type = $types[(string) ($block['type'] ?? '')] ?? null;
                    $data = $block['data'] ?? null;

                    if (is_null($type) || ! is_array($data)) {
                        continue;
                    }

                    array_push($pendingIds, ...$type->mediaIds($data));
                }

                $imageId = $content['page']['og_image_id'] ?? null;

                if (is_numeric($imageId) && (int) $imageId !== ($pageImageIds[$revision->page_id] ?? null)) {
                    $pendingIds[] = (int) $imageId;
                }

                foreach (array_unique($pendingIds) as $id) {
                    if (isset($liveIds[$revision->page_id][$id])) {
                        continue;
                    }

                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            });

        $settings = SiteSettings::current();

        $referencedIds = [
            ...array_values($pageImageIds),
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
