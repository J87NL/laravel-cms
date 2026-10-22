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
#[Description('Publishes a page: a draft or unpublished page goes live, and the pending revision of a page (see page_get) is applied to the live page. Visitors see the result at once; a revision is recorded and the page cache is flushed. Publishing a pending revision applies only what it changed compared with its base, the live page as it was when the revision started: changed fields and blocks are written, added blocks are created and removed blocks are deleted. Changes made directly on the live page after the revision started stay, unless the pending revision changed the same field or block: then the pending revision wins. When the pending revision changed the block order, its order replaces the live order. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first.')]
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

        $refusal = $this->publicationRefusal($user, $page);

        if (! is_null($refusal)) {
            return $refusal;
        }

        $publishPage->handle($page, $this->userId($user));

        $page->refresh();
        $presented = $this->presenter->page($page);
        $message = $presented['is_public']
            ? sprintf('Page "%s" is published at %s.', $page->title, $page->url())
            : sprintf('Page "%s" is published, but stays hidden from visitors until its parent pages are published too.', $page->title);

        return Response::structured([
            'message' => $message,
            'page' => $presented,
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
