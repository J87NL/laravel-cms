<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Validation\ValidationException;
use J87NL\Cms\Media\MediaUsage;
use J87NL\Cms\Models\MediaAsset;

class DeleteMedia
{
    public function __construct(
        private readonly MediaUsage $usage,
    ) {}

    public function handle(MediaAsset $asset): void
    {
        if ($this->usage->count($asset) > 0) {
            throw ValidationException::withMessages([
                'media' => __('cms::validation.media_in_use'),
            ]);
        }

        $asset->delete();
    }
}
