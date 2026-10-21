<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Models\MediaAsset;

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

        $cleaned = $this->cleaner->clean((string) $file->getRealPath(), (string) $file->getMimeType());

        try {
            return DB::transaction(function () use ($file, $attributes, $cleaned): MediaAsset {
                $asset = MediaAsset::query()
                    ->create(Arr::only($attributes, [
                        'alt',
                        'caption',
                        'focal_x',
                        'focal_y',
                    ]));

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

    private function fileName(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = str($name)->slug()->toString();

        return sprintf('%s.%s', $slug === '' ? 'image' : $slug, $file->guessExtension() ?? 'jpg');
    }
}
