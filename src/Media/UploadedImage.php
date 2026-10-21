<?php

declare(strict_types=1);

namespace J87NL\Cms\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UploadedImage
{
    public function assertUsable(UploadedFile $file): void
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

    public function name(UploadedFile $file): string
    {
        return pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
    }

    public function fileName(UploadedFile $file): string
    {
        $slug = str($this->name($file))->slug()->toString();

        return sprintf('%s.%s', $slug === '' ? 'image' : $slug, $file->guessExtension() ?? 'jpg');
    }
}
