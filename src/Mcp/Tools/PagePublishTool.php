<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\PublishPage;
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

#[Name('page_publish')]
#[Title('Publish CMS page')]
#[Description('Publishes a page: a draft or unpublished page goes live, and the pending revision of a page (see page_get) replaces the live content. Visitors see the result at once; a revision is recorded and the page cache is flushed. Publishing a pending revision replaces the live page fields and blocks with the pending ones, including changes made directly on the live page after the pending revision was started. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first.')]
#[IsReadOnly(false)]
#[IsDestructive]
class PagePublishTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, PublishPage $publishPage): Response|ResponseFactory
    {
        $user = $this->authorizePublication($request);

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

        if (! is_null($page->publish_at)) {
            return Response::error(sprintf('Page %d "%s" is scheduled to be published on %s, so these tools cannot change it. Change the page or its schedule in the CMS admin.', $page->id, $page->title, $page->publish_at->toIso8601String()));
        }

        $publishPage->handle($page, $this->userId($user));

        $page->refresh();

        return Response::structured([
            'message' => sprintf('Page "%s" is published at %s.', $page->title, $page->url()),
            'page' => $this->presenter->page($page),
        ]);
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
