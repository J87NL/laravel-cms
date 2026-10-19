<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
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

#[Name('page_update')]
#[Title('Update CMS page')]
#[Description('Changes the fields of a draft or unpublished page; only the fields you pass change. Published pages and pages with a planned publication are refused: change those in the CMS admin. Status, publication and home page cannot be changed with this tool. Changing slug or parent_id changes the address of the page and its subpages.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class PageUpdateTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, SavePage $savePage): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $data = $request->validate([
            'page_id' => ['required', 'integer'],
            ...$this->pageRules(false),
        ]);

        $attributes = Arr::except($data, ['page_id']);

        if ($attributes === []) {
            return Response::error('Nothing to change: pass at least one page field, such as title or meta_description.');
        }

        return $this->onWritablePage($user, (int) $data['page_id'], function (Page $page) use ($savePage, $attributes, $user): ResponseFactory {
            $savePage->handle($page, $attributes, $this->userId($user));

            return Response::structured([
                'message' => sprintf('Page "%s" saved.', $page->title),
                'page' => $this->presenter->page($page->refresh()),
            ]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()->description('The id of the page, from pages_list.')->required(),
            'title' => $schema->string()->description('The page title, at most 160 characters.'),
            ...$this->pageSchema($schema),
        ];
    }
}
