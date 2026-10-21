<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Validation\MediaRules;

class UpdateMedia
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(MediaAsset $asset, array $attributes): MediaAsset
    {
        $rules = array_map(
            fn (array $fieldRules): array => ['sometimes', ...$fieldRules],
            MediaRules::fields(),
        );

        $validated = Validator::make(Arr::only($attributes, array_keys($rules)), $rules)->validate();

        $asset->update($validated);

        return $asset;
    }
}
