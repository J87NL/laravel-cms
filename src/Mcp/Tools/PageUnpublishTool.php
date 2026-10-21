<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Enums\PageStatus;
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

#[Name('page_unpublish')]
#[Title('Unpublish CMS page')]
#[Description('Takes a published page offline: visitors get a not-found page and the page cache is flushed. The home page, and a page that contains the home page, cannot be taken offline. A pending revision of the page is applied to the page as it goes offline, so its changes are kept and edits to the offline page go to the page directly. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first.')]
#[IsReadOnly(false)]
#[IsDestructive]
class PageUnpublishTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, ChangePageStatus $changePageStatus): Response|ResponseFactory
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

        if (! $page->isPublished()) {
            return Response::error(sprintf('Page %d "%s" is not published, so there is nothing to take offline.', $page->id, $page->title));
        }

        $changePageStatus->handle($page, PageStatus::Unpublished, $this->userId($user));

        return Response::structured([
            'message' => sprintf('Page "%s" is offline.', $page->title),
            'page' => $this->presenter->page($page->refresh()),
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
