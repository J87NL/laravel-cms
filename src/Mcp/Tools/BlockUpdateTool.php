<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Page;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('block_update')]
#[Title('Update CMS block')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class BlockUpdateTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function description(): string
    {
        return sprintf('%s The block type cannot change: remove the block and add a new one instead. Images are referenced by their id from media_list (media_id, media_ids). %s', $this->modeDescription(), $this->blockTypesSummary());
    }

    protected function modeDescription(): string
    {
        return 'Replaces the data of a block; pass every field of the block, as page_get shows them. On a published page the change is stored in the pending revision of the page and the live page stays as it is until page_publish; on a draft or unpublished page it is saved directly. Pass page_id for a block that only exists in the pending revision (a negative id). Pages with a planned publication are refused. To change a block on a published page directly, use block_update_live.';
    }

    public function handle(Request $request, SaveBlock $saveBlock, BlockRegistry $registry): Response|ResponseFactory
    {
        $user = $this->authorizePageWrite($request);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'block_id' => ['required', 'integer'],
            'page_id' => ['sometimes', 'integer'],
            'data' => ['present', 'array'],
        ]);

        $blockId = (int) $input['block_id'];
        $pageId = $this->blockPageId($input);

        if (is_null($pageId)) {
            return $this->blockNotFound($blockId);
        }

        return $this->onWritablePage($user, $pageId, function (Page $page) use ($saveBlock, $registry, $input, $blockId, $user): Response|ResponseFactory {
            if ($this->writesPending($page)) {
                $pendingBlock = $this->pendingEditor()->block($page, $blockId);

                if (is_null($pendingBlock)) {
                    return $this->blockNotFound($blockId);
                }

                $type = $registry->get((string) ($pendingBlock['type'] ?? ''));

                if (is_null($type)) {
                    return $this->unknownBlockType((string) ($pendingBlock['type'] ?? ''));
                }

                $data = $this->validateBlockData($type, $input['data']);

                $revision = $this->pendingEditor()->updateBlock($page, $blockId, $type, $data, $this->userId($user));

                return $this->pendingResponse($page, $revision, sprintf('Block %d is changed in the pending revision of page "%s".', $blockId, $page->title), [
                    'block' => $this->presenter->pendingBlock((array) $this->pendingEditor()->block($page, $blockId)),
                ]);
            }

            $block = $this->lockedBlock($page, $blockId);

            if (is_null($block)) {
                return $this->blockNotFound($blockId);
            }

            $type = $block->blockType();

            if (is_null($type)) {
                return $this->unknownBlockType($block->type);
            }

            $data = $this->validateBlockData($type, $input['data']);

            $saveBlock->handle($page, $block, $type, $data, $this->userId($user));

            return Response::structured([
                'message' => sprintf('Block %d on page "%s" saved.', $block->id, $page->title),
                'block' => $this->presenter->block($block->refresh()),
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
            'data' => $schema->object()->description('All fields of the block for its type, see the tool description.')->required(),
        ];
    }
}
