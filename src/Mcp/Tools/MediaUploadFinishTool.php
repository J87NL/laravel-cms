<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Mcp\ImageUploader;
use J87NL\Cms\Models\MediaUploadSession;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('media_upload_finish')]
#[Title('Finish a chunked CMS image upload')]
#[Description('Finishes a chunked image upload: checks that every chunk arrived (and lists the missing indexes otherwise), joins them, checks the SHA-256 of the whole file and adds the image to the media library with the same checks as media_upload. Returns the image with its id; use that id in block data (media_id, media_ids) or as og_image_id.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class MediaUploadFinishTool extends Tool
{
    use InteractsWithCms;

    private const int LISTED_MISSING = 50;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, ImageUploader $uploader): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $session = MediaUploadSession::findOpen((string) $input['upload_id'], (string) $user->getAuthIdentifier());

        if (is_null($session)) {
            return Response::error(sprintf('Upload %s was not found. It may have expired after an hour or been finished; start a new one with media_upload_start.', $input['upload_id']));
        }

        $missing = $this->missingChunks($session);

        if ($missing !== []) {
            return Response::error(sprintf('Upload %s is missing %d of its %d chunks: %s. Send them with media_upload_chunk and call media_upload_finish again.', $session->id, count($missing), $session->chunkCount(), $this->listed($missing)));
        }

        $claimed = MediaUploadSession::query()
            ->whereKey($session->id)
            ->whereNull('finishing_at')
            ->update(['finishing_at' => now()]);

        if ($claimed !== 1) {
            return Response::error(sprintf('Upload %s is already being finished.', $session->id));
        }

        $path = tempnam(sys_get_temp_dir(), ImageUploader::TEMPORARY_PREFIX);

        if ($path === false) {
            $this->release($session);

            return ImageUploader::storageFailed();
        }

        try {
            $joined = $this->checkedJoin($session, $path);
        } catch (Throwable $exception) {
            $this->release($session);
            unlink($path);

            throw $exception;
        }

        if ($joined instanceof Response) {
            $this->release($session);
            unlink($path);

            return $joined;
        }

        try {
            $asset = $uploader->fromPath($path, $session->file_name, $session->media_attributes);
        } finally {
            $this->discard($session);

            if (is_file($path)) {
                unlink($path);
            }
        }

        if ($asset instanceof Response) {
            return $asset;
        }

        return Response::structured([
            'message' => sprintf('Image %d uploaded. Use its id in block data (media_id, media_ids) or as og_image_id.', $asset->id),
            'media' => $this->presenter->media($asset->refresh()->load('media'), 0),
        ]);
    }

    /**
     * @return list<int>
     */
    private function missingChunks(MediaUploadSession $session): array
    {
        $disk = Storage::disk(MediaUploadSession::disk());
        $missing = [];

        for ($index = 0; $index < $session->chunkCount(); $index++) {
            if (! $disk->exists($session->chunkPath($index))) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * @param  list<int>  $indexes
     */
    private function listed(array $indexes): string
    {
        $listed = implode(', ', array_slice($indexes, 0, self::LISTED_MISSING));

        return count($indexes) > self::LISTED_MISSING ? sprintf('%s and %d more', $listed, count($indexes) - self::LISTED_MISSING) : $listed;
    }

    /**
     * Joins the chunks into $path; a Response when the joined file cannot be used, null when it matches.
     */
    private function checkedJoin(MediaUploadSession $session, string $path): ?Response
    {
        if (! $this->join($session, $path)) {
            return ImageUploader::storageFailed();
        }

        $size = (int) filesize($path);

        if ($size !== $session->size || ! ImageUploader::matchesSha256((string) hash_file('sha256', $path), $session->sha256)) {
            return Response::error(sprintf('The joined file of upload %s (%d bytes) does not match the size and sha256 given to media_upload_start, so nothing was stored. Check those values and the order of the chunks, then send the wrong chunks again or start over.', $session->id, $size));
        }

        return null;
    }

    private function join(MediaUploadSession $session, string $path): bool
    {
        $disk = Storage::disk(MediaUploadSession::disk());
        $target = fopen($path, 'wb');

        if ($target === false) {
            return false;
        }

        try {
            for ($index = 0; $index < $session->chunkCount(); $index++) {
                $chunk = $disk->readStream($session->chunkPath($index));

                if (! is_resource($chunk)) {
                    return false;
                }

                stream_copy_to_stream($chunk, $target);
                fclose($chunk);
            }
        } finally {
            fclose($target);
        }

        return true;
    }

    private function release(MediaUploadSession $session): void
    {
        MediaUploadSession::query()
            ->whereKey($session->id)
            ->update(['finishing_at' => null]);
    }

    private function discard(MediaUploadSession $session): void
    {
        Storage::disk(MediaUploadSession::disk())->deleteDirectory($session->directory());

        $session->delete();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->description('The upload_id from media_upload_start.')->required(),
        ];
    }
}
