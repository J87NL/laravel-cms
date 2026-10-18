<?php

declare(strict_types=1);

namespace J87NL\Cms\Media;

use GdImage;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImageCleaner
{
    /**
     * @return array{path: string, width: int, height: int, temporary: bool}
     */
    public function clean(string $source, string $mimeType): array
    {
        $this->assertWithinPixelBudget($source);

        if ($mimeType === 'image/gif' && $this->isAnimatedGif($source)) {
            throw $this->invalid('cms::validation.image_animated');
        }

        $image = $this->load($source, $mimeType);
        $image = $this->orient($image, $this->orientation($source, $mimeType));
        $target = $this->temporaryPath();

        try {
            $this->save($image, $target, $mimeType);
        } catch (Throwable $exception) {
            $this->remove($target);

            throw $exception;
        }

        return [
            'path' => $target,
            'width' => imagesx($image),
            'height' => imagesy($image),
            'temporary' => true,
        ];
    }

    public function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function orient(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            $image = $rotated instanceof GdImage ? $rotated : $image;
        }

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private function assertWithinPixelBudget(string $source): void
    {
        $dimensions = @getimagesize($source);

        if (! is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw $this->invalid('cms::validation.image_unreadable');
        }

        $budget = (int) config('cms.media.max_pixels', 40_000_000);

        if ($budget < $dimensions[0] * $dimensions[1]) {
            throw $this->invalid('cms::validation.image_too_large', [
                'megapixels' => (int) floor($budget / 1_000_000),
            ]);
        }
    }

    private function load(string $source, string $mimeType): GdImage
    {
        try {
            $image = match ($mimeType) {
                'image/jpeg' => imagecreatefromjpeg($source),
                'image/png' => imagecreatefrompng($source),
                'image/webp' => imagecreatefromwebp($source),
                'image/gif' => imagecreatefromgif($source),
                default => false,
            };
        } catch (Throwable) {
            throw $this->invalid('cms::validation.image_unreadable');
        }

        if (! $image instanceof GdImage) {
            throw $this->invalid('cms::validation.image_unreadable');
        }

        return $image;
    }

    private function orientation(string $source, string $mimeType): int
    {
        if ($mimeType !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($source);

        if (! is_array($exif)) {
            return 1;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function isAnimatedGif(string $source): bool
    {
        $contents = (string) file_get_contents($source);

        return preg_match_all('/\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $contents) > 1;
    }

    private function save(GdImage $image, string $target, string $mimeType): void
    {
        imagesavealpha($image, true);

        try {
            $saved = match ($mimeType) {
                'image/png' => imagepng($image, $target),
                'image/webp' => imagewebp($image, $target, 90),
                'image/gif' => imagegif($image, $target),
                default => imagejpeg($image, $target, 90),
            };
        } catch (Throwable) {
            $saved = false;
        }

        if (! $saved) {
            throw $this->invalid('cms::validation.image_unreadable');
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cms-image-');

        if ($path === false) {
            throw $this->invalid('cms::validation.image_unreadable');
        }

        return $path;
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function invalid(string $message, array $replace = []): ValidationException
    {
        return ValidationException::withMessages([
            'file' => __($message, $replace),
        ]);
    }
}
