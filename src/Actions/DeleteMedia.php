<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Media\MediaUsage;
use J87NL\Cms\Models\MediaAsset;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class DeleteMedia
{
    public function __construct(
        private readonly MediaUsage $usage,
        private readonly Filesystem $filesystem,
    ) {}

    public function handle(MediaAsset $asset): void
    {
        DB::transaction(function () use ($asset): void {
            $locked = MediaAsset::query()
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->usage->count($locked) > 0) {
                throw ValidationException::withMessages([
                    'media' => __('cms::validation.media_in_use'),
                ]);
            }

            $media = $locked->media()
                ->get();

            $media->each(fn (Media $item): ?bool => $item->deleteQuietly());

            $locked->delete();

            DB::afterCommit(fn () => $media->each(fn (Media $item) => $this->removeFiles($item)));
        });
    }

    private function removeFiles(Media $item): void
    {
        try {
            $this->filesystem->removeAllFiles($item);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
