<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use J87NL\Cms\Enums\EditMode;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('block_remove_live')]
#[Title('Remove CMS block from a live page')]
#[Description('Removes a block directly, also from a published page: visitors no longer see it. A revision is recorded and the page cache is flushed; the previous version can be restored in the CMS admin while it is among the most recent revisions (cms.revisions.keep, 20 by default). Needs the CMS publish permission. Pages with a planned publication are refused. Confirm with the user first; to propose the removal for review instead, use block_remove.')]
#[IsReadOnly(false)]
#[IsDestructive]
class BlockRemoveLiveTool extends BlockRemoveTool
{
    protected function mode(): EditMode
    {
        return EditMode::Live;
    }
}
