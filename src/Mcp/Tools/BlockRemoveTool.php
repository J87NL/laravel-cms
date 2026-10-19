<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\DeleteBlock;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Block;
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
#[Description('Removes a block from a draft or unpublished page; confirm with the user first. The previous version stays in the page revisions and can be restored in the CMS admin, but only the most recent revisions are kept (cms.revisions.keep, 20 by default), so many later changes make it unrecoverable. Published pages and pages with a planned publication are refused: change those in the CMS admin.')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlockRemoveTool extends Tool
{
    use InteractsWithCms;

    public function handle(Request $request, DeleteBlock $deleteBlock): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'block_id' => ['required', 'integer'],
        ]);

        $block = Block::query()
            ->find((int) $input['block_id']);

        if (is_null($block)) {
            return $this->blockNotFound((int) $input['block_id']);
        }

        return $this->onWritablePage($user, $block->page_id, function (Page $page) use ($deleteBlock, $input, $user): Response|ResponseFactory {
            $block = $this->lockedBlock($page, (int) $input['block_id']);

            if (is_null($block)) {
                return $this->blockNotFound((int) $input['block_id']);
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
        ];
    }
}
