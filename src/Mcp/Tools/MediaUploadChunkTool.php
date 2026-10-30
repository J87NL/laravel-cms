<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use J87NL\Cms\CmsPermission;
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

#[Name('media_upload_chunk')]
#[Title('Send a chunk of a CMS image upload')]
#[Description('Sends one chunk of a chunked image upload started with media_upload_start. Pass upload_id, index (0-based), the chunk bytes as base64 in content_base64 and the SHA-256 of those chunk bytes in sha256. Every chunk holds chunk_bytes bytes of the file, the last one the rest. A damaged chunk is refused on its own: send just that index again. Sending an index again replaces it.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class MediaUploadChunkTool extends Tool
{
    use InteractsWithCms;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'upload_id' => ['required', 'string'],
            'index' => ['required', 'integer', 'min:0'],
            'content_base64' => ['required', 'string', sprintf('max:%d', intdiv(MediaUploadSession::CHUNK_BYTES + 2, 3) * 4 + 512)],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ], [
            'sha256.regex' => 'sha256 must be the SHA-256 of the chunk as 64 hexadecimal characters.',
        ]);

        $session = MediaUploadSession::findOpen((string) $input['upload_id'], (string) $user->getAuthIdentifier());

        if (is_null($session)) {
            return $this->uploadNotFound((string) $input['upload_id']);
        }

        if (! is_null($session->finishing_at)) {
            return Response::error(sprintf('Upload %s is being finished, so it takes no more chunks.', $session->id));
        }

        $index = (int) $input['index'];

        if ($index >= $session->chunkCount()) {
            return Response::error(sprintf('Upload %s has chunks 0 to %d, so there is no chunk %d.', $session->id, $session->chunkCount() - 1, $index));
        }

        $bytes = base64_decode((string) preg_replace('/\s+/', '', (string) $input['content_base64']), true);

        if ($bytes === false || $bytes === '') {
            return Response::error(sprintf('Chunk %d is not valid base64, so it was not stored. Send it again in standard base64 (A-Z, a-z, 0-9, + and /, padded with =).', $index));
        }

        if (! ImageUploader::matchesSha256(hash('sha256', $bytes), (string) $input['sha256'])) {
            return Response::error(sprintf('Chunk %d arrived damaged: the sha256 checksum does not match the %d bytes that were received, so it was not stored. Send chunk %d again.', $index, strlen($bytes), $index));
        }

        if (strlen($bytes) !== $session->chunkSize($index)) {
            return Response::error(sprintf('Chunk %d holds %d bytes, but it must hold %d bytes of the file, so it was not stored.', $index, strlen($bytes), $session->chunkSize($index)));
        }

        if (! Storage::disk(MediaUploadSession::disk())->put($session->chunkPath($index), $bytes)) {
            return ImageUploader::storageFailed();
        }

        return Response::structured([
            'message' => sprintf('Chunk %d of upload %s stored.', $index, $session->id),
            'upload_id' => $session->id,
            'index' => $index,
        ]);
    }

    private function uploadNotFound(string $uploadId): Response
    {
        return Response::error(sprintf('Upload %s was not found. It may have expired after an hour or been finished; start a new one with media_upload_start.', $uploadId));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->description('The upload_id from media_upload_start.')->required(),
            'index' => $schema->integer()->description('The 0-based number of the chunk.')->required(),
            'content_base64' => $schema->string()->description(sprintf('The bytes of this chunk in standard base64: %d bytes of the file, the last chunk the rest.', MediaUploadSession::CHUNK_BYTES))->required(),
            'sha256' => $schema->string()->description('The SHA-256 of the bytes of this chunk, as 64 hexadecimal characters.')->required(),
        ];
    }
}
