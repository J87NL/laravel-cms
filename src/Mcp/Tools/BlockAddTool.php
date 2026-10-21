<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Blocks\BlockRegistry;
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

#[Name('block_add')]
#[Title('Add CMS block')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class BlockAddTool extends Tool
{
    use InteractsWithCms;

    public function __construct(private readonly CmsPresenter $presenter) {}

    public function description(): string
    {
        return sprintf('%s Rich text is HTML and is cleaned the same way as in the admin. Images are referenced by their id from media_list (media_id, media_ids). %s', $this->modeDescription(), $this->blockTypesSummary());
    }

    protected function modeDescription(): string
    {
        return 'Adds a content block at the end of a page; reorder with blocks_reorder. On a published page the block is added to the pending revision of the page (with a negative id until it is published) and the live page stays as it is until page_publish; on a draft or unpublished page it is added directly. Pages with a planned publication are refused. To add a block to a published page directly, use block_add_live.';
    }

    public function handle(Request $request, SaveBlock $saveBlock, BlockRegistry $registry): Response|ResponseFactory
    {
        $user = $this->authorizePageWrite($request);

        if ($user instanceof Response) {
            return $user;
        }

        $input = $request->validate([
            'page_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'data' => ['present', 'array'],
        ]);

        return $this->onWritablePage($user, (int) $input['page_id'], function (Page $page) use ($saveBlock, $registry, $input, $user): Response|ResponseFactory {
            $type = $registry->get((string) $input['type']);

            if (is_null($type)) {
                return $this->unknownBlockType((string) $input['type']);
            }

            $data = $this->validateBlockData($type, $input['data']);

            if ($this->writesPending($page)) {
                [$revision, $added] = $this->pendingEditor()->addBlock($page, $type, $data, $this->userId($user));

                return $this->pendingResponse($page, $revision, sprintf('Block %d (%s) is added to the pending revision of page "%s".', $added['id'], $type->key(), $page->title), [
                    'block' => $this->presenter->pendingBlock($added),
                ]);
            }

            $block = $saveBlock->handle($page, new Block, $type, $data, $this->userId($user));

            return Response::structured([
                'message' => sprintf('Block %d (%s) added to page "%s".', $block->id, $type->key(), $page->title),
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
            'page_id' => $schema->integer()->description('The id of the page, from pages_list.')->required(),
            'type' => $schema->string()->description('The block type key, for example rich_text.')->required(),
            'data' => $schema->object()->description('The block fields for this type, see the tool description.')->required(),
        ];
    }
}
