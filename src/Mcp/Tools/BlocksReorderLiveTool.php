<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use J87NL\Cms\Enums\EditMode;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('blocks_reorder_live')]
#[Title('Reorder CMS blocks on a live page')]
#[Description('Sets the order of the blocks on a page directly, also when it is published: visitors see the new order at once. Pass every block id of the live page exactly once, in the new order (see page_get). A revision is recorded and the page cache is flushed. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first; to propose a new order for review instead, use blocks_reorder.')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlocksReorderLiveTool extends BlocksReorderTool
{
    protected function mode(): EditMode
    {
        return EditMode::Live;
    }
}
