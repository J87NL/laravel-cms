<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\DeleteMedia;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Media\MediaUsage;
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

#[Name('media_delete')]
#[Title('Delete CMS images')]
#[Description('Permanently deletes images from the media library, with their files, when they are not in use anywhere: not in a block, not as the share image (og_image_id) of a page, not in a pending revision and not in the site settings. An image in use is refused and left alone. Every id is handled on its own, so one image in use or not found does not stop the others; each gets a status: deleted, in_use (with usage_count) or not_found. This cannot be undone, so confirm with the user first. Use media_list to see which images are in_use. Replacing the file of an image is only possible in the CMS admin.')]
#[IsReadOnly(false)]
#[IsDestructive]
class MediaDeleteTool extends Tool
{
    use InteractsWithCms;

    public const int MAX_IDS = 25;

    public function handle(Request $request, DeleteMedia $deleteMedia, MediaUsage $mediaUsage): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', sprintf('max:%d', self::MAX_IDS)],
            'media_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $results = [];

        foreach (array_map('intval', $input['media_ids']) as $id) {
            $results[] = $this->delete($id, $deleteMedia, $mediaUsage);
        }

        $deleted = count(array_filter($results, fn (array $result): bool => $result['status'] === 'deleted'));

        return Response::structured([
            'message' => sprintf('%d of %d images deleted. Images in use or not found were left alone.', $deleted, count($results)),
            'results' => $results,
        ]);
    }

    /**
     * @return array{id: int, status: string, alt?: string|null, file_name?: string|null, usage_count?: int}
     */
    private function delete(int $id, DeleteMedia $deleteMedia, MediaUsage $mediaUsage): array
    {
        $asset = MediaAsset::query()
            ->with('media')
            ->find($id);

        if (is_null($asset)) {
            return ['id' => $id, 'status' => 'not_found'];
        }

        $details = [
            'alt' => $asset->alt,
            'file_name' => $asset->file()?->file_name,
        ];

        try {
            $deleteMedia->handle($asset);
        } catch (ModelNotFoundException) {
            return ['id' => $id, 'status' => 'not_found'];
        } catch (ValidationException $exception) {
            if (! array_key_exists('media', $exception->errors())) {
                throw $exception;
            }

            return ['id' => $id, 'status' => 'in_use', ...$details, 'usage_count' => $mediaUsage->count($asset)];
        }

        return ['id' => $id, 'status' => 'deleted', ...$details];
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'media_ids' => $schema->array()->items($schema->integer())->description(sprintf('The ids of the images to delete, from media_list: 1 to %d distinct ids.', self::MAX_IDS))->required(),
        ];
    }
}
