<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use J87NL\Cms\Enums\EditMode;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('block_add_live')]
#[Title('Add CMS block to a live page')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlockAddLiveTool extends BlockAddTool
{
    protected function mode(): EditMode
    {
        return EditMode::Live;
    }

    protected function modeDescription(): string
    {
        return 'Adds a content block at the end of a page directly, also when it is published: visitors see it at once. A revision is recorded and the page cache is flushed. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first; to propose a block for review instead, use block_add.';
    }
}
