<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Blocks\BlockRegistry;
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
        return sprintf('Adds a content block at the end of a draft or unpublished page; reorder with blocks_reorder. Published pages and pages with a planned publication are refused: change those in the CMS admin. Rich text is HTML and is cleaned the same way as in the admin. Images are referenced by their id from media_list (media_id, media_ids). %s', $this->blockTypesSummary());
    }

    public function handle(Request $request, SaveBlock $saveBlock, BlockRegistry $registry): Response|ResponseFactory
    {
        $user = $this->authorizeWrite($request, CmsPermission::PagesEdit);

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
