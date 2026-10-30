<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Mcp\ImageUploader;
use J87NL\Cms\Media\Remote\RemoteImageDownloader;
use J87NL\Cms\Media\Remote\RemoteImageException;
use J87NL\Cms\Models\MediaAsset;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('media_upload')]
#[Title('Upload a CMS image')]
#[Description('Uploads an image to the media library and returns it with its id; use that id in block data (media_id, media_ids) or as og_image_id. Pass the file either as base64 in content_base64 (a data: URL prefix is allowed) with its file_name, or as source_url, a public https:// address of an image that is already online. Pass sha256, the SHA-256 of the file bytes before encoding, so a damaged transfer is refused as such instead of as an unreadable image. For a large file, or one with long repetitive runs such as a palette PNG, use media_upload_start, media_upload_chunk and media_upload_finish instead. Accepts PNG, JPEG, WebP and GIF (no SVG, no animated GIF); the type is read from the content, not from the file name. alt text is required. The image is re-encoded without its metadata. The smaller WebP versions are made by the queue, so the original is served until the queue has run. Deleting or replacing images is only possible in the CMS admin.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class MediaUploadTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, ImageUploader $uploader, RemoteImageDownloader $downloader): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $maxKilobytes = ImageUploader::maxKilobytes();

        $input = $request->validate([
            'file_name' => ['required_without:source_url', 'nullable', 'string', 'max:255'],
            'content_base64' => ['required_without:source_url', 'prohibits:source_url', 'nullable', 'string', ...($maxKilobytes > 0 ? [sprintf('max:%d', $this->maxEncodedLength($maxKilobytes))] : [])],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'sha256' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'alt' => ['required', 'string'],
            'caption' => ['nullable', 'string'],
            'focal_x' => ['nullable', 'integer'],
            'focal_y' => ['nullable', 'integer'],
        ], [
            'sha256.regex' => 'sha256 must be the SHA-256 of the file as 64 hexadecimal characters.',
        ]);

        $asset = filled($input['source_url'] ?? null)
            ? $this->fromUrl($input, $uploader, $downloader)
            : $this->fromBase64($input, $uploader);

        if ($asset instanceof Response) {
            return $asset;
        }

        return Response::structured([
            'message' => sprintf('Image %d uploaded. Use its id in block data (media_id, media_ids) or as og_image_id.', $asset->id),
            'media' => $this->presenter->media($asset->refresh()->load('media'), 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fromBase64(array $input, ImageUploader $uploader): MediaAsset|Response
    {
        $maxKilobytes = ImageUploader::maxKilobytes();
        $encoded = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^,]*;base64,/i', '', trim((string) $input['content_base64'])));

        if ($maxKilobytes > 0 && intdiv(strlen($encoded), 4) * 3 > $maxKilobytes * 1024 + 2) {
            return ImageUploader::tooLarge();
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return Response::error('content_base64 is not valid base64. Pass the bytes of the image file in standard base64 (A-Z, a-z, 0-9, + and /, padded with =).');
        }

        if (filled($input['sha256'] ?? null) && ! ImageUploader::matchesSha256(hash('sha256', $bytes), (string) $input['sha256'])) {
            return ImageUploader::checksumMismatch(strlen($bytes));
        }

        if (ImageUploader::exceedsLimit(strlen($bytes))) {
            return ImageUploader::tooLarge();
        }

        return $uploader->fromBytes($bytes, (string) $input['file_name'], $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fromUrl(array $input, ImageUploader $uploader, RemoteImageDownloader $downloader): MediaAsset|Response
    {
        $path = tempnam(sys_get_temp_dir(), ImageUploader::TEMPORARY_PREFIX);

        if ($path === false) {
            return ImageUploader::storageFailed();
        }

        try {
            try {
                $urlFileName = $downloader->download((string) $input['source_url'], $path, ImageUploader::maxKilobytes() * 1024);
            } catch (RemoteImageException $exception) {
                return Response::error($exception->getMessage());
            }

            if (filled($input['sha256'] ?? null) && ! ImageUploader::matchesSha256((string) hash_file('sha256', $path), (string) $input['sha256'])) {
                return ImageUploader::checksumMismatch((int) filesize($path), 'The image at source_url');
            }

            $fileName = filled($input['file_name'] ?? null) ? (string) $input['file_name'] : $urlFileName;

            return $uploader->fromPath($path, $fileName === '' ? 'image' : $fileName, $input);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_name' => $schema->string()->description('The original file name with its extension, for example "github.png". The stored file name is derived from it. Required with content_base64; with source_url it defaults to the last part of the address.'),
            'content_base64' => $schema->string()->description($this->contentDescription()),
            'source_url' => $schema->string()->description('Instead of content_base64: a public https:// address of the image, for example one already on a website. Private and local addresses are refused, at most 3 redirects are followed and the download stops after 10 seconds or past the size limit. Files in your own sandbox have no public address; send those as content_base64 or in chunks.'),
            'sha256' => $schema->string()->description('Optional but recommended: the SHA-256 of the file bytes before base64 encoding, as 64 hexadecimal characters. A mismatch is refused as a damaged transfer, with the number of bytes that arrived.'),
            'alt' => $schema->string()->description('The alt text that describes the image, at most 250 characters.')->required(),
            'caption' => $schema->string()->description('An optional caption, at most 500 characters.'),
            'focal_x' => $schema->integer()->description('The horizontal focal point in percent (0 to 100) for cropping; the centre when left out.'),
            'focal_y' => $schema->integer()->description('The vertical focal point in percent (0 to 100) for cropping; the centre when left out.'),
        ];
    }

    private function contentDescription(): string
    {
        $maxKilobytes = (int) config('cms.media.max_upload_kilobytes', 10240);
        $limit = $maxKilobytes > 0 ? sprintf(', at most %d kB before encoding', $maxKilobytes) : '';

        return sprintf('The bytes of a PNG, JPEG, WebP or GIF image in standard base64%s. A data: URL prefix such as "data:image/png;base64," is allowed.', $limit);
    }

    /**
     * The longest content_base64 that can hold a file of the limit, with room for a data: URL prefix and line breaks.
     */
    private function maxEncodedLength(int $maxKilobytes): int
    {
        return intdiv($maxKilobytes * 1024 + 2, 3) * 4 + 1024 + intdiv($maxKilobytes * 1024, 57) * 2;
    }
}
