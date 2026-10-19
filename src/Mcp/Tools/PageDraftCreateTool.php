<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SavePage;
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

#[Name('page_draft_create')]
#[Title('Create CMS draft page')]
#[Description('Creates a new page as a draft. The draft is not visible on the website and no tool can publish it: someone with publish rights publishes it in the CMS admin. Add content afterwards with block_add; share images come from media_list (og_image_id).')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class PageDraftCreateTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, SavePage $savePage): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate($this->pageRules(true));

        $page = $savePage->handle(new Page, $data, $this->userId($user));

        return Response::structured([
            'message' => sprintf('Draft page "%s" created at %s. It stays hidden until it is published in the CMS admin.', $page->title, $page->url()),
            'page' => $this->presenter->page($page->refresh()),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The page title, at most 160 characters.')->required(),
            ...$this->pageSchema($schema),
        ];
    }
}
