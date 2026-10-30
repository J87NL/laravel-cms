<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use finfo;
use Illuminate\Http\UploadedFile;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\Models\MediaAsset;
use Laravel\Mcp\Response;

/**
 * The last step of every MCP image upload: read the real type of the file, refuse anything the media library does
 * not accept and hand the rest to UploadMedia, so an MCP upload gets the same checks as one in the admin.
 */
class ImageUploader
{
    public const string TEMPORARY_PREFIX = 'cms-mcp-upload-';

    public function __construct(private readonly UploadMedia $uploadMedia) {}

    public static function maxKilobytes(): int
    {
        return (int) config('cms.media.max_upload_kilobytes', 10240);
    }

    public static function exceedsLimit(int $bytes): bool
    {
        $maxKilobytes = self::maxKilobytes();

        return $maxKilobytes > 0 && $bytes > $maxKilobytes * 1024;
    }

    public static function tooLarge(): Response
    {
        return Response::error(sprintf('The image is larger than %d kB, so nothing was uploaded. Upload a smaller image.', self::maxKilobytes()));
    }

    public static function checksumMismatch(int $receivedBytes, string $subject = 'The upload'): Response
    {
        return Response::error(sprintf('%s arrived damaged: the sha256 checksum does not match the %d bytes that were received, so nothing was stored. Send it again; for a large or repetitive file, use media_upload_start to upload it in chunks.', $subject, $receivedBytes));
    }

    public static function storageFailed(): Response
    {
        return Response::error('The image could not be stored temporarily, so nothing was uploaded. Try again later.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fromBytes(string $bytes, string $fileName, array $attributes): MediaAsset|Response
    {
        $path = tempnam(sys_get_temp_dir(), self::TEMPORARY_PREFIX);

        if ($path === false) {
            return self::storageFailed();
        }

        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                return self::storageFailed();
            }

            return $this->fromPath($path, $fileName, $attributes);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The file at $path is read but not removed; the caller owns it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fromPath(string $path, string $fileName, array $attributes): MediaAsset|Response
    {
        $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($mimeType, MediaAsset::ACCEPTED_MIME_TYPES, true)) {
            return Response::error(sprintf('The content is %s, which the media library does not accept. Upload a PNG, JPEG, WebP or GIF image; the type is read from the content, not from the file name.', $mimeType === '' ? 'of an unknown type' : $mimeType));
        }

        return $this->uploadMedia->handle(
            new UploadedFile($path, $fileName, $mimeType, null, true),
            self::attributes($attributes),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function attributes(array $input): array
    {
        return array_filter([
            'alt' => $input['alt'] ?? null,
            'caption' => $input['caption'] ?? null,
            'focal_x' => $input['focal_x'] ?? null,
            'focal_y' => $input['focal_y'] ?? null,
        ], fn (mixed $value): bool => ! is_null($value));
    }

    public static function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/i', $value) === 1;
    }

    public static function matchesSha256(string $bytesHash, string $expected): bool
    {
        return hash_equals(strtolower($expected), $bytesHash);
    }
}
