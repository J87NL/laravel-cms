<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Media\UploadedImage;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Validation\MediaRules;

class UploadMedia
{
    public function __construct(
        private readonly ImageCleaner $cleaner,
        private readonly UploadedImage $upload,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(UploadedFile $file, array $attributes): MediaAsset
    {
        $this->upload->assertUsable($file);

        $attributes = $this->validated($attributes);

        $cleaned = $this->cleaner->clean((string) $file->getRealPath(), (string) $file->getMimeType());

        try {
            return DB::transaction(function () use ($file, $attributes, $cleaned): MediaAsset {
                $asset = MediaAsset::query()
                    ->create($attributes);

                $asset->addMedia($cleaned['path'])
                    ->usingFileName($this->upload->fileName($file))
                    ->usingName($this->upload->name($file))
                    ->withCustomProperties([
                        'width' => $cleaned['width'],
                        'height' => $cleaned['height'],
                    ])
                    ->toMediaCollection(MediaAsset::COLLECTION);

                return $asset;
            });
        } finally {
            $this->cleaner->remove($cleaned['path']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $rules = MediaRules::fields();

        foreach (array_keys($rules) as $field) {
            if ($field === 'alt') {
                continue;
            }

            $rules[$field] = ['sometimes', ...$rules[$field]];
        }

        return Validator::make(Arr::only($attributes, array_keys($rules)), $rules)->validate();
    }
}
