<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp\Tools;

use J87NL\Cms\Enums\EditMode;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('page_update_live')]
#[Title('Update published CMS page')]
#[Description('Changes the fields of a page directly, also when it is published: visitors see the change at once. Only the fields you pass change. A revision is recorded and the page cache is flushed. Needs the CMS publish permission. Pages with a planned publication are refused. Status, publication and home page cannot be changed with this tool. Changing slug or parent_id changes the address of the page and its subpages. Confirm with the user first; to propose a change for review instead, use page_update.')]
#[IsReadOnly(false)]
#[IsDestructive]
class PageUpdateLiveTool extends PageUpdateTool
{
    protected function mode(): EditMode
    {
        return EditMode::Live;
    }
}
