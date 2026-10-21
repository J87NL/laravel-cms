<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use J87NL\Cms\Enums\EditMode;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('block_update_live')]
#[Title('Update CMS block on a live page')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlockUpdateLiveTool extends BlockUpdateTool
{
    protected function mode(): EditMode
    {
        return EditMode::Live;
    }

    protected function modeDescription(): string
    {
        return 'Replaces the data of a block directly, also on a published page: visitors see the change at once. Pass every field of the block, as page_get shows them. A revision is recorded and the page cache is flushed. Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first; to propose a change for review instead, use block_update.';
    }
}
