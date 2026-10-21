<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Media\UploadedImage;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReplaceMediaFile
{
    public const string RETIRED_COLLECTION = 'replaced';

    public function __construct(
        private readonly ImageCleaner $cleaner,
        private readonly UploadedImage $upload,
    ) {}

    public function handle(MediaAsset $asset, UploadedFile $file): MediaAsset
    {
        $this->upload->assertUsable($file);

        $cleaned = $this->cleaner->clean((string) $file->getRealPath(), (string) $file->getMimeType());

        try {
            DB::transaction(function () use ($asset, $file, $cleaned): void {
                $locked = MediaAsset::query()
                    ->whereKey($asset->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $previous = $locked->media()
                    ->where('collection_name', MediaAsset::COLLECTION)
                    ->get();

                $previous->each(fn (Media $media): bool => $media->forceFill([
                    'collection_name' => self::RETIRED_COLLECTION,
                ])->save());

                $media = $locked->addMedia($cleaned['path'])
                    ->usingFileName($this->upload->fileName($file))
                    ->usingName($this->upload->name($file))
                    ->withCustomProperties([
                        'width' => $cleaned['width'],
                        'height' => $cleaned['height'],
                    ])
                    ->toMediaCollection(MediaAsset::COLLECTION);

                $locked->touch();

                DB::afterRollBack(fn () => app(Filesystem::class)->removeAllFiles($media));

                DB::afterCommit(fn () => $previous->each(fn (Media $media): ?bool => $media->delete()));
            });
        } finally {
            $this->cleaner->remove($cleaned['path']);
        }

        return $asset->refresh();
    }
}
