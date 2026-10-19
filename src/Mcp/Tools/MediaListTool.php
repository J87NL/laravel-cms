<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
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

#[Name('media_list')]
#[Title('List CMS images')]
#[Description('Lists the images in the media library, newest first, with id, alt text, caption, an image url and whether the image is in use. Use the ids in block data (media_id, media_ids) or as og_image_id. Paginated: pass page for more. Uploading or deleting images is only possible in the CMS admin.')]
#[IsReadOnly]
#[IsDestructive(false)]
class MediaListTool extends Tool
{
    use InteractsWithCms;

    private const int PER_PAGE_DEFAULT = 25;

    private const int PER_PAGE_MAX = 100;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, MediaUsage $mediaUsage): Response|ResponseFactory
    {
        $user = $this->authorizeRead($request, CmsPermission::Media);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', sprintf('max:%d', self::PER_PAGE_MAX)],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        $paginator = MediaAsset::query()
            ->with('media')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->whereRaw("alt like ? escape '!'", [$this->likePattern($search)])
                ->orWhereRaw("caption like ? escape '!'", [$this->likePattern($search)])))
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? self::PER_PAGE_DEFAULT), ['*'], 'page', (int) ($data['page'] ?? 1));

        $usage = $mediaUsage->counts();

        return Response::structured([
            'media' => array_values(array_map(
                fn (MediaAsset $asset): array => $this->presenter->media($asset, $usage[$asset->id] ?? 0),
                $paginator->items(),
            )),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only images whose alt text or caption contains this text.'),
            'page' => $schema->integer()->description('The page of results, starting at 1.'),
            'per_page' => $schema->integer()->description(sprintf('Images per page, at most %d (default %d).', self::PER_PAGE_MAX, self::PER_PAGE_DEFAULT)),
        ];
    }
}
