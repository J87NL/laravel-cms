<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\DiscardPendingRevision;
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

#[Name('revision_discard')]
#[Title('Discard pending CMS revision')]
#[Description('Throws away the pending revision of a page, with every change in it; the live page stays as it is. This cannot be undone. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first.')]
#[IsReadOnly(false)]
#[IsDestructive]
class RevisionDiscardTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, DiscardPendingRevision $discardPendingRevision): Response|ResponseFactory
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

        if (! $discardPendingRevision->handle($page)) {
            return Response::error(sprintf('Page %d "%s" has no pending revision, so nothing was discarded.', $page->id, $page->title));
        }

        return Response::structured([
            'message' => sprintf('The pending revision of page "%s" is discarded; the live page is unchanged.', $page->title),
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
