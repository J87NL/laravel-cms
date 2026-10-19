<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Page;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('pages_list')]
#[Title('List CMS pages')]
#[Description('Lists every page of the website as a tree, with id, title, path, status (draft, published or unpublished), is_home, is_public (published and all parent pages published) and writable. Only writable pages (drafts and unpublished pages without a planned publication) can be changed with page_update and the block tools; publishing, scheduling and deleting pages only happen in the CMS admin. Use the ids with page_get.')]
#[IsReadOnly]
#[IsDestructive(false)]
class PagesListTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeRead($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $pages = Page::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Response::structured([
            'pages' => $this->presenter->pageTree($pages),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
