<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Mcp\CmsPresenter;
use J87NL\Cms\Mcp\Concerns\InteractsWithCms;
use J87NL\Cms\Models\Block;
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
        return sprintf('Replaces the data of a block on a draft or unpublished page; pass every field of the block, as page_get shows them. The block type cannot change: remove the block and add a new one instead. Published pages and pages with a planned publication are refused: change those in the CMS admin. Images are referenced by their id from media_list (media_id, media_ids). %s', $this->blockTypesSummary());
    }

    public function handle(Request $request, SaveBlock $saveBlock): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::PagesEdit);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'block_id' => ['required', 'integer'],
            'data' => ['present', 'array'],
        ]);

        $block = Block::query()
            ->find((int) $input['block_id']);

        if (is_null($block)) {
            return $this->blockNotFound((int) $input['block_id']);
        }

        return $this->onWritablePage($user, $block->page_id, function (Page $page) use ($saveBlock, $input, $user): Response|ResponseFactory {
            $block = $this->lockedBlock($page, (int) $input['block_id']);

            if (is_null($block)) {
                return $this->blockNotFound((int) $input['block_id']);
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
            'data' => $schema->object()->description('All fields of the block for its type, see the tool description.')->required(),
        ];
    }
}
