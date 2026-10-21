<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Validation\MediaRules;

class UploadMedia
{
    public function __construct(
        private readonly ImageCleaner $cleaner,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(UploadedFile $file, array $attributes): MediaAsset
    {
        $this->assertSize($file);

        $attributes = $this->validated($attributes);

        $cleaned = $this->cleaner->clean((string) $file->getRealPath(), (string) $file->getMimeType());

        try {
            return DB::transaction(function () use ($file, $attributes, $cleaned): MediaAsset {
                $asset = MediaAsset::query()
                    ->create($attributes);

                $asset->addMedia($cleaned['path'])
                    ->usingFileName($this->fileName($file))
                    ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
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

    private function assertSize(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages([
                'file' => __('cms::validation.image_unreadable'),
            ]);
        }

        $maxKilobytes = (int) config('cms.media.max_upload_kilobytes', 10240);

        if ($maxKilobytes <= 0 || (int) $file->getSize() <= $maxKilobytes * 1024) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => __('cms::validation.media_too_large', [
                'max' => $maxKilobytes,
            ]),
        ]);
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

    private function fileName(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = str($name)->slug()->toString();

        return sprintf('%s.%s', $slug === '' ? 'image' : $slug, $file->guessExtension() ?? 'jpg');
    }
}
