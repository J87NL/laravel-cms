<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Mcp\ImageUploader;
use J87NL\Cms\Models\MediaUploadSession;
use J87NL\Cms\Validation\MediaRules;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('media_upload_start')]
#[Title('Start a chunked CMS image upload')]
#[Description('Starts a chunked image upload, for a file that is large or has long repetitive runs (such as a palette PNG), where one long base64 string in media_upload easily gets damaged. Pass file_name, size (the number of bytes), sha256 (the SHA-256 of the whole file) and the image fields alt, caption, focal_x and focal_y. Returns an upload_id, the chunk size and the number of chunks. Then send every chunk with media_upload_chunk (a damaged chunk is refused on its own, so only that one is sent again) and finish with media_upload_finish. An upload expires an hour after its last chunk.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class MediaUploadStartTool extends Tool
{
    use InteractsWithCms;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'alt' => ['required', 'string'],
            'caption' => ['nullable', 'string'],
            'focal_x' => ['nullable', 'integer'],
            'focal_y' => ['nullable', 'integer'],
        ], [
            'sha256.regex' => 'sha256 must be the SHA-256 of the file as 64 hexadecimal characters.',
        ]);

        if (ImageUploader::exceedsLimit((int) $input['size'])) {
            return ImageUploader::tooLarge();
        }

        $attributes = $this->validatedAttributes(ImageUploader::attributes($input));
        $userId = (string) $user->getAuthIdentifier();
        $maxOpen = (int) config('cms.media.chunked_uploads.max_open_per_user', 5);

        if ($maxOpen > 0 && MediaUploadSession::query()->openFor($userId)->count() >= $maxOpen) {
            return Response::error(sprintf('You already have %d unfinished chunked uploads, the most allowed. Finish one with media_upload_finish, or wait until it expires after an hour.', $maxOpen));
        }

        $session = MediaUploadSession::query()
            ->create([
                'user_id' => $userId,
                'file_name' => $input['file_name'],
                'size' => (int) $input['size'],
                'sha256' => strtolower((string) $input['sha256']),
                'media_attributes' => $attributes,
                'expires_at' => MediaUploadSession::newExpiry(),
            ]);

        return Response::structured([
            'message' => sprintf('Upload %s started. Send chunks 0 to %d with media_upload_chunk, each holding %d bytes of the file (the last one the rest), then call media_upload_finish.', $session->id, $session->chunkCount() - 1, MediaUploadSession::CHUNK_BYTES),
            'upload_id' => $session->id,
            'chunk_bytes' => MediaUploadSession::CHUNK_BYTES,
            'chunk_count' => $session->chunkCount(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedAttributes(array $attributes): array
    {
        $rules = MediaRules::fields();

        foreach (array_keys($rules) as $field) {
            if ($field !== 'alt') {
                $rules[$field] = ['sometimes', ...$rules[$field]];
            }
        }

        return Validator::make($attributes, $rules)->validate();
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_name' => $schema->string()->description('The original file name with its extension, for example "logo.png".')->required(),
            'size' => $schema->integer()->description('The size of the whole file in bytes.')->required(),
            'sha256' => $schema->string()->description('The SHA-256 of the whole file, as 64 hexadecimal characters.')->required(),
            'alt' => $schema->string()->description('The alt text that describes the image, at most 250 characters.')->required(),
            'caption' => $schema->string()->description('An optional caption, at most 500 characters.'),
            'focal_x' => $schema->integer()->description('The horizontal focal point in percent (0 to 100) for cropping; the centre when left out.'),
            'focal_y' => $schema->integer()->description('The vertical focal point in percent (0 to 100) for cropping; the centre when left out.'),
        ];
    }
}
