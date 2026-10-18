<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

use Illuminate\Support\Collection;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;

final readonly class BlockContext
{
    /**
     * @param  Collection<int, MediaAsset>  $media
     */
    public function __construct(
        public Page $page,
        public int $position,
        public Collection $media,
    ) {}

    public function isFirst(): bool
    {
        return $this->position === 0;
    }

    public function asset(mixed $id): ?MediaAsset
    {
        if (! is_numeric($id)) {
            return null;
        }

        return $this->media->get((int) $id);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<MediaAsset>
     */
    public function assets(array $ids): array
    {
        $assets = [];

        foreach ($ids as $id) {
            $asset = $this->asset($id);

            if (! is_null($asset)) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }
}
