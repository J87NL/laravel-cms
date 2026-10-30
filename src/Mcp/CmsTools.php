<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use J87NL\Cms\Mcp\Tools\BlockAddLiveTool;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveLiveTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderLiveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateLiveTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateTool;
use J87NL\Cms\Mcp\Tools\MediaListTool;
use J87NL\Cms\Mcp\Tools\MediaUploadChunkTool;
use J87NL\Cms\Mcp\Tools\MediaUploadFinishTool;
use J87NL\Cms\Mcp\Tools\MediaUploadStartTool;
use J87NL\Cms\Mcp\Tools\MediaUploadTool;
use J87NL\Cms\Mcp\Tools\MenuItemSaveTool;
use J87NL\Cms\Mcp\Tools\MenuItemsListTool;
use J87NL\Cms\Mcp\Tools\PageDraftCreateTool;
use J87NL\Cms\Mcp\Tools\PageGetTool;
use J87NL\Cms\Mcp\Tools\PagePublishTool;
use J87NL\Cms\Mcp\Tools\PagesListTool;
use J87NL\Cms\Mcp\Tools\PageUnpublishTool;
use J87NL\Cms\Mcp\Tools\PageUpdateLiveTool;
use J87NL\Cms\Mcp\Tools\PageUpdateTool;
use J87NL\Cms\Mcp\Tools\RedirectDeleteTool;
use J87NL\Cms\Mcp\Tools\RedirectSaveTool;
use J87NL\Cms\Mcp\Tools\RedirectsListTool;
use J87NL\Cms\Mcp\Tools\RevisionDiscardTool;
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
        MediaUploadTool::class,
        MediaUploadStartTool::class,
        MediaUploadChunkTool::class,
        MediaUploadFinishTool::class,
        MenuItemsListTool::class,
        RedirectsListTool::class,
        PageDraftCreateTool::class,
        PageUpdateTool::class,
        BlockAddTool::class,
        BlockUpdateTool::class,
        BlockRemoveTool::class,
        BlocksReorderTool::class,
        PageUpdateLiveTool::class,
        BlockAddLiveTool::class,
        BlockUpdateLiveTool::class,
        BlockRemoveLiveTool::class,
        BlocksReorderLiveTool::class,
        PagePublishTool::class,
        PageUnpublishTool::class,
        RevisionDiscardTool::class,
        MenuItemSaveTool::class,
        RedirectSaveTool::class,
        RedirectDeleteTool::class,
    ];

    public const string INSTRUCTIONS = <<<'MARKDOWN'
        CMS tools manage the website's pages, blocks, menus and redirects. They never schedule, make a home page,
        delete pages or delete or replace media: those happen in the CMS admin.

        - Read the site with pages_list and page_get; page_get returns the blocks in their order with their data, and
          the pending revision of the page when it has one.
        - page_draft_create creates a new draft page.
        - page_update, block_add, block_update, block_remove and blocks_reorder are the draft tools. On a draft or
          unpublished page they change the page directly. On a published page they collect the changes in one pending
          revision; the live page stays as it is until someone publishes it.
        - page_update_live, block_add_live, block_update_live, block_remove_live and blocks_reorder_live change the
          page directly, also when it is published. Visitors see the change at once; confirm with the user first.
        - page_publish publishes a draft page, or the pending revision of a published page. page_unpublish takes a page
          offline and revision_discard throws a pending revision away. Confirm these with the user first.
        - Pages with a planned publication (publish_at) cannot be changed with these tools.
        - block_add lists the block types and their fields in its description; a validation error names the field.
        - Images come from media_list; use their id in block data (media_id, media_ids) or as og_image_id. media_upload
          adds a PNG, JPEG, WebP or GIF image (base64 with alt text, or a public https source_url) and returns its id.
          Pass sha256 so a damaged transfer is reported as such. For a large or repetitive file, use
          media_upload_start, media_upload_chunk and media_upload_finish: a damaged chunk is resent on its own.
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
