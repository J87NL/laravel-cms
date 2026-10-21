<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use J87NL\Cms\Media\ImageCleaner;
use J87NL\Cms\Media\UploadedImage;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class ReplaceMediaFile
{
    public const string RETIRED_COLLECTION = 'replaced';

    public function __construct(
        private readonly ImageCleaner $cleaner,
        private readonly UploadedImage $upload,
        private readonly Filesystem $filesystem,
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
                    ->whereIn('collection_name', [
                        MediaAsset::COLLECTION,
                        self::RETIRED_COLLECTION,
                    ])
                    ->get();

                $previous->each(fn (Media $item): bool => $item->forceFill([
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

                DB::afterRollBack(fn () => $this->filesystem->removeAllFiles($media));

                $locked->touch();

                DB::afterCommit(fn () => $this->retire($previous));
            });
        } finally {
            $this->cleaner->remove($cleaned['path']);
        }

        return $asset->refresh();
    }

    /**
     * @param  Collection<int, Media>  $previous
     */
    private function retire(Collection $previous): void
    {
        foreach ($previous as $item) {
            try {
                $this->filesystem->removeAllFiles($item);
                $item->deleteQuietly();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
