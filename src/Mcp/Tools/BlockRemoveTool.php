<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\DeleteBlock;
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

#[Name('block_remove')]
#[Title('Remove CMS block')]
#[Description('Removes a block; confirm with the user first. On a published page the block is removed from the pending revision of the page and the live page keeps it until page_publish; on a draft or unpublished page it is removed directly. The previous version stays in the page revisions and can be restored in the CMS admin, but only the most recent revisions are kept (cms.revisions.keep, 20 by default), so many later changes make it unrecoverable. Pass page_id for a block that only exists in the pending revision (a negative id). Pages with a planned publication are refused. To remove a block from a published page directly, use block_remove_live.')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlockRemoveTool extends Tool
{
    use InteractsWithCms;

    public function handle(Request $request, DeleteBlock $deleteBlock): Response|ResponseFactory
    {
        $user = $this->authorizePageWrite($request);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'block_id' => ['required', 'integer'],
            'page_id' => ['sometimes', 'integer'],
        ]);

        $blockId = (int) $input['block_id'];
        $pageId = $this->blockPageId($input);

        if (is_null($pageId)) {
            return $this->blockNotFound($blockId);
        }

        return $this->onWritablePage($user, $pageId, function (Page $page) use ($deleteBlock, $blockId, $user): Response|ResponseFactory {
            if ($this->writesPending($page)) {
                if (is_null($this->pendingEditor()->block($page, $blockId))) {
                    return $this->blockNotFound($blockId);
                }

                $revision = $this->pendingEditor()->removeBlock($page, $blockId, $this->userId($user));

                return $this->pendingResponse($page, $revision, sprintf('Block %d is removed from the pending revision of page "%s".', $blockId, $page->title), [
                    'page_id' => $page->id,
                ]);
            }

            $block = $this->lockedBlock($page, $blockId);

            if (is_null($block)) {
                return $this->blockNotFound($blockId);
            }

            $deleteBlock->handle($block, $this->userId($user));

            return Response::structured([
                'message' => sprintf('Block %d removed from page "%s".', $block->id, $page->title),
                'page_id' => $page->id,
            ]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->integer()->description('The id of the block, from page_get.')->required(),
            'page_id' => $schema->integer()->description('The id of the page; needed for a block that only exists in the pending revision.'),
        ];
    }
}
