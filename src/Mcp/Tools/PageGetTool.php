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

#[Name('page_get')]
#[Title('Get CMS page')]
#[Description('Returns one page with its live fields (title, slug, SEO fields, status, schedule) and its live blocks in display order, each with id, type, position, is_visible and data. pending_revision holds the pending revision of a published page (its page fields and blocks, blocks added there have a negative id) and under changes a summary of what publishing it would change compared with the live page: changed fields with their live and pending value, and the added, removed, changed (with the changed data keys) and moved blocks; or null. writable is false for pages with a planned publication (publish_at): those can only be changed in the CMS admin. Use the block ids with block_update, block_remove and blocks_reorder.')]
#[IsReadOnly]
#[IsDestructive(false)]
class PageGetTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->authorizeRead($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'page_id' => ['required', 'integer'],
        ]);

        $page = Page::query()
            ->find((int) $data['page_id']);

        if (is_null($page)) {
            return $this->pageNotFound((int) $data['page_id']);
        }

        return Response::structured($this->presenter->page($page));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->description('The id of the page, from pages_list.')->required(),
        ];
    }
}
