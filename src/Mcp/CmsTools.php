<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateTool;
use J87NL\Cms\Mcp\Tools\MediaListTool;
use J87NL\Cms\Mcp\Tools\MenuItemSaveTool;
use J87NL\Cms\Mcp\Tools\MenuItemsListTool;
use J87NL\Cms\Mcp\Tools\PageDraftCreateTool;
use J87NL\Cms\Mcp\Tools\PageGetTool;
use J87NL\Cms\Mcp\Tools\PagesListTool;
use J87NL\Cms\Mcp\Tools\PageUpdateTool;
use J87NL\Cms\Mcp\Tools\RedirectDeleteTool;
use J87NL\Cms\Mcp\Tools\RedirectSaveTool;
use J87NL\Cms\Mcp\Tools\RedirectsListTool;
use Laravel\Mcp\Server\Tool;

class CmsTools
{
    public const string GRANT_LEVEL_HEADER = 'X-J87-Grant-Level';

    public const string FULL_GRANT = 'full';

    public const string PAGE_WRITE_ABILITY = 'cms.pages.write';

    /**
     * @var list<class-string<Tool>>
     */
    public const array TOOLS = [
        PagesListTool::class,
        PageGetTool::class,
        MediaListTool::class,
        MenuItemsListTool::class,
        RedirectsListTool::class,
        PageDraftCreateTool::class,
        PageUpdateTool::class,
        BlockAddTool::class,
        BlockUpdateTool::class,
        BlockRemoveTool::class,
        BlocksReorderTool::class,
        MenuItemSaveTool::class,
        RedirectSaveTool::class,
        RedirectDeleteTool::class,
    ];

    public const string INSTRUCTIONS = <<<'MARKDOWN'
        CMS tools manage the website's pages, blocks, menus and redirects. They never publish, unpublish, schedule,
        make a home page, delete pages or upload media: publishing always happens in the CMS admin.

        - Read the site with pages_list and page_get; page_get returns the blocks in their order with their data.
        - page_draft_create creates a new draft page. page_update, block_add, block_update, block_remove and
          blocks_reorder only work on draft or unpublished pages without a planned publication; other pages are
          changed in the CMS admin.
        - block_add lists the block types and their fields in its description; a validation error names the field.
        - Images come from media_list; use their id in block data (media_id, media_ids) or as og_image_id.
        - menu_item_save may link a menu item to any page, but the item only shows while that page is published.
        - redirect_save and redirect_delete take effect on the live site immediately; confirm with the user first.
        MARKDOWN;

    /**
     * @return list<class-string<Tool>>
     */
    public static function all(): array
    {
        return self::TOOLS;
    }
}
