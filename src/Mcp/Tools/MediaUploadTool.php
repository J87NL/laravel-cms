<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use finfo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\UploadMedia;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
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
#[Description('Uploads an image to the media library and returns it with its id; use that id in block data (media_id, media_ids) or as og_image_id. Pass the file as base64 in content_base64 (a data: URL prefix is allowed) with its file_name. Accepts PNG, JPEG, WebP and GIF (no SVG, no animated GIF); the type is read from the content, not from the file name. alt text is required. The image is re-encoded without its metadata. The smaller WebP versions are made by the queue, so the original is served until the queue has run. Deleting or replacing images is only possible in the CMS admin.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class MediaUploadTool extends Tool
{
    use InteractsWithCms;

    private const string TEMPORARY_PREFIX = 'cms-mcp-upload-';

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, UploadMedia $uploadMedia): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'content_base64' => ['required', 'string'],
            'alt' => ['required', 'string'],
            'caption' => ['nullable', 'string'],
            'focal_x' => ['nullable', 'integer'],
            'focal_y' => ['nullable', 'integer'],
        ]);

        $encoded = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^,]*;base64,/i', '', trim((string) $input['content_base64'])));
        $maxKilobytes = (int) config('cms.media.max_upload_kilobytes', 10240);

        if ($maxKilobytes > 0 && intdiv(strlen($encoded), 4) * 3 > $maxKilobytes * 1024 + 2) {
            return $this->tooLarge($maxKilobytes);
        }

        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return Response::error('content_base64 is not valid base64. Pass the bytes of the image file, base64 encoded.');
        }

        if ($maxKilobytes > 0 && strlen($bytes) > $maxKilobytes * 1024) {
            return $this->tooLarge($maxKilobytes);
        }

        $path = tempnam(sys_get_temp_dir(), self::TEMPORARY_PREFIX);

        if ($path === false) {
            return Response::error('The image could not be stored temporarily, so nothing was uploaded. Try again later.');
        }

        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                return Response::error('The image could not be stored temporarily, so nothing was uploaded. Try again later.');
            }

            $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

            if (! in_array($mimeType, MediaAsset::ACCEPTED_MIME_TYPES, true)) {
                return Response::error(sprintf('The content is %s, which the media library does not accept. Upload a PNG, JPEG, WebP or GIF image; the type is read from the content, not from the file name.', $mimeType === '' ? 'of an unknown type' : $mimeType));
            }

            $asset = $uploadMedia->handle(
                new UploadedFile($path, (string) $input['file_name'], $mimeType, null, true),
                array_filter([
                    'alt' => $input['alt'],
                    'caption' => $input['caption'] ?? null,
                    'focal_x' => $input['focal_x'] ?? null,
                    'focal_y' => $input['focal_y'] ?? null,
                ], fn (mixed $value): bool => ! is_null($value)),
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return Response::structured([
            'message' => sprintf('Image %d uploaded. Use its id in block data (media_id, media_ids) or as og_image_id.', $asset->id),
            'media' => $this->presenter->media($asset->refresh()->load('media'), 0),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_name' => $schema->string()->description('The original file name with its extension, for example "github.png". The stored file name is derived from it.')->required(),
            'content_base64' => $schema->string()->description(sprintf('The bytes of a PNG, JPEG, WebP or GIF image, base64 encoded, at most %d kB before encoding. A data: URL prefix such as "data:image/png;base64," is allowed.', (int) config('cms.media.max_upload_kilobytes', 10240)))->required(),
            'alt' => $schema->string()->description('The alt text that describes the image, at most 250 characters.')->required(),
            'caption' => $schema->string()->description('An optional caption, at most 500 characters.'),
            'focal_x' => $schema->integer()->description('The horizontal focal point in percent (0 to 100) for cropping; the centre when left out.'),
            'focal_y' => $schema->integer()->description('The vertical focal point in percent (0 to 100) for cropping; the centre when left out.'),
        ];
    }

    private function tooLarge(int $maxKilobytes): Response
    {
        return Response::error(sprintf('The image is larger than %d kB, so nothing was uploaded. Upload a smaller image.', $maxKilobytes));
    }
}
