<?php

declare(strict_types=1);

namespace J87NL\Cms\Media;

use Illuminate\Validation\Rules\Exists;
use J87NL\Cms\Models\MediaAsset;

class MediaOptions
{
    /**
     * @return list<array{value: int, label: string}>
     */
    public static function all(): array
    {
        return array_values(MediaAsset::query()
            ->orderByDesc('id')
            ->get([
                'id',
                'alt',
            ])
            ->map(fn (MediaAsset $asset): array => [
                'value' => $asset->id,
                'label' => sprintf('%s (#%d)', $asset->alt, $asset->id),
            ])
            ->all());
    }

    public static function existsRule(): Exists
    {
        return new Exists((new MediaAsset)->getTable(), 'id');
    }
}
