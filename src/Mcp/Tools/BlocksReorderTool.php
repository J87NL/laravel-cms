<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\ReorderBlocks;
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

#[Name('blocks_reorder')]
#[Title('Reorder CMS blocks')]
#[Description('Sets the order of the blocks on a page. Pass every block id of the page exactly once, in the new order (see page_get; on a published page use the block ids of its pending revision when it has one). On a published page the new order is stored in the pending revision of the page and the live page stays as it is until page_publish; on a draft or unpublished page it is saved directly. Pages with a planned publication are refused. To reorder the blocks of a published page directly, use blocks_reorder_live.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class BlocksReorderTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function handle(Request $request, ReorderBlocks $reorderBlocks): Response|ResponseFactory
    {
        $user = $this->authorizePageWrite($request);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'page_id' => ['required', 'integer'],
            'block_ids' => ['required', 'array', 'min:1'],
            'block_ids.*' => ['integer', 'distinct'],
        ]);

        $blockIds = array_values(array_map('intval', $input['block_ids']));

        return $this->onWritablePage($user, (int) $input['page_id'], function (Page $page) use ($reorderBlocks, $blockIds, $user): ResponseFactory {
            if ($this->writesPending($page)) {
                $revision = $this->pendingEditor()->reorderBlocks($page, $blockIds, $this->userId($user));

                return $this->pendingResponse($revision, sprintf('The new block order of page "%s" is saved in its pending revision.', $page->title));
            }

            $reorderBlocks->handle($page, $blockIds, $this->userId($user));

            return Response::structured([
                'message' => $this->liveMessage($page, sprintf('The blocks of page "%s" are reordered.', $page->title)),
                'blocks' => $this->presenter->page($page)['blocks'],
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
            'block_ids' => $schema->array()->items($schema->integer())->description('Every block id of the page, in the new order.')->required(),
        ];
    }
}
