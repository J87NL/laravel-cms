<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Facades\Cms;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Mcp\Tools\BlockAddLiveTool;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveLiveTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderLiveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateLiveTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateTool;
use J87NL\Cms\Mcp\Tools\MediaListTool;
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
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\MediaUploadSession;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Tool;

/**
 * @return array<string, array{class-string<Tool>, CmsPermission, Closure(): array<string, mixed>}>
 */
function cmsWriteTools(): array
{
    return [
        'page_draft_create' => [PageDraftCreateTool::class, CmsPermission::PagesEdit, fn (): array => ['title' => 'Nieuw']],
        'page_update' => [PageUpdateTool::class, CmsPermission::PagesEdit, fn (): array => ['page_id' => makePage(['title' => 'Concept'], PageStatus::Draft)->id, 'title' => 'Ander']],
        'block_add' => [BlockAddTool::class, CmsPermission::PagesEdit, fn (): array => ['page_id' => makePage(['title' => 'Concept'], PageStatus::Draft)->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Hoi</p>']]],
        'block_update' => [BlockUpdateTool::class, CmsPermission::PagesEdit, fn (): array => ['block_id' => addBlock(makePage(['title' => 'Concept'], PageStatus::Draft), 'rich_text', ['html' => '<p>Oud</p>'])->id, 'data' => ['html' => '<p>Nieuw</p>']]],
        'block_remove' => [BlockRemoveTool::class, CmsPermission::PagesEdit, fn (): array => ['block_id' => addBlock(makePage(['title' => 'Concept'], PageStatus::Draft), 'rich_text', ['html' => '<p>Oud</p>'])->id]],
        'blocks_reorder' => [BlocksReorderTool::class, CmsPermission::PagesEdit, function (): array {
            $page = makePage(['title' => 'Concept'], PageStatus::Draft);
            $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
            $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

            return ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]];
        }],
        'page_update_live' => [PageUpdateLiveTool::class, CmsPermission::PagesPublish, fn (): array => ['page_id' => makePage(['title' => 'Live'])->id, 'title' => 'Ander']],
        'block_add_live' => [BlockAddLiveTool::class, CmsPermission::PagesPublish, fn (): array => ['page_id' => makePage(['title' => 'Live'])->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Hoi</p>']]],
        'block_update_live' => [BlockUpdateLiveTool::class, CmsPermission::PagesPublish, fn (): array => ['block_id' => addBlock(makePage(['title' => 'Live']), 'rich_text', ['html' => '<p>Oud</p>'])->id, 'data' => ['html' => '<p>Nieuw</p>']]],
        'block_remove_live' => [BlockRemoveLiveTool::class, CmsPermission::PagesPublish, fn (): array => ['block_id' => addBlock(makePage(['title' => 'Live']), 'rich_text', ['html' => '<p>Oud</p>'])->id]],
        'blocks_reorder_live' => [BlocksReorderLiveTool::class, CmsPermission::PagesPublish, function (): array {
            $page = makePage(['title' => 'Live']);
            $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
            $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

            return ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]];
        }],
        'page_publish' => [PagePublishTool::class, CmsPermission::PagesPublish, fn (): array => ['page_id' => makePage(['title' => 'Concept'], PageStatus::Draft)->id]],
        'page_unpublish' => [PageUnpublishTool::class, CmsPermission::PagesPublish, fn (): array => ['page_id' => makePage(['title' => 'Live', 'slug' => 'live'])->id]],
        'revision_discard' => [RevisionDiscardTool::class, CmsPermission::PagesPublish, fn (): array => ['page_id' => pendingPage()->id]],
        'menu_item_save' => [MenuItemSaveTool::class, CmsPermission::Menus, fn (): array => ['menu' => 'main', 'url' => '/contact', 'label' => 'Contact']],
        'redirect_save' => [RedirectSaveTool::class, CmsPermission::Redirects, fn (): array => ['from_path' => '/oud', 'to' => '/nieuw']],
        'redirect_delete' => [RedirectDeleteTool::class, CmsPermission::Redirects, fn (): array => ['redirect_id' => makeRedirect('oud', '/nieuw')->id]],
        'media_upload_start' => [MediaUploadStartTool::class, CmsPermission::Media, fn (): array => ['file_name' => 'foto.png', 'size' => 100, 'sha256' => str_repeat('a', 64), 'alt' => 'Een foto']],
        'media_upload' => [MediaUploadTool::class, CmsPermission::Media, fn (): array => ['file_name' => 'foto.png', 'content_base64' => pngBase64(), 'alt' => 'Een foto']],
    ];
}

/**
 * @return array<string, array{class-string<Tool>, CmsPermission, Closure(): array<string, mixed>}>
 */
function cmsReadTools(): array
{
    return [
        'pages_list' => [PagesListTool::class, CmsPermission::PagesEdit, fn (): array => []],
        'page_get' => [PageGetTool::class, CmsPermission::PagesEdit, fn (): array => ['page_id' => makePage(['title' => 'Over'])->id]],
        'media_list' => [MediaListTool::class, CmsPermission::Media, fn (): array => []],
        'menu_items_list' => [MenuItemsListTool::class, CmsPermission::Menus, fn (): array => []],
        'redirects_list' => [RedirectsListTool::class, CmsPermission::Redirects, fn (): array => []],
    ];
}

/**
 * @return array<string, int|string>
 */
function cmsContentCounts(): array
{
    $blocks = Block::query()
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get();

    $pages = Page::query()
        ->orderBy('id')
        ->get();

    return [
        'pages' => $pages->count(),
        'titles' => $pages->pluck('title')->implode('|'),
        'statuses' => $pages->map(fn (Page $page): string => $page->status->value)->implode('|'),
        'pending_revisions' => Revision::query()
            ->pending()
            ->get()
            ->map(fn (Revision $revision): string => (string) json_encode($revision->snapshot))
            ->implode('|'),
        'blocks' => $blocks->pluck('id')->implode(','),
        'block_data' => $blocks->map(fn (Block $block): string => (string) json_encode($block->data))->implode('|'),
        'menu_items' => MenuItem::query()
            ->count(),
        'redirects' => Redirect::query()
            ->count(),
        'media' => MediaAsset::query()
            ->count(),
        'media_upload_sessions' => MediaUploadSession::query()
            ->count(),
    ];
}

it('registers every CMS tool with explicit read-only and destructive hints', function (): void {
    $annotations = collect(Cms::mcpTools())
        ->mapWithKeys(fn (string $class): array => [app($class)->name() => app($class)->annotations()]);

    $annotations->each(fn (array $hints, string $name) => expect($hints)->toHaveKeys(['readOnlyHint', 'destructiveHint'], $name));

    expect($annotations->filter(fn (array $hints): bool => $hints['readOnlyHint'])->keys()->all())
        ->toEqualCanonicalizing(['pages_list', 'page_get', 'media_list', 'menu_items_list', 'redirects_list'])
        ->and($annotations->filter(fn (array $hints): bool => $hints['destructiveHint'])->keys()->all())
        ->toEqualCanonicalizing([
            'block_remove',
            'page_update_live',
            'block_add_live',
            'block_update_live',
            'block_remove_live',
            'blocks_reorder_live',
            'page_publish',
            'page_unpublish',
            'revision_discard',
            'menu_item_save',
            'redirect_delete',
        ]);
});

it('refuses every tool without an acting user', function (string $tool, CmsPermission $permission, Closure $arguments): void {
    CmsTestServer::tool($tool, $arguments())
        ->assertHasErrors(['No acting user is known']);
})->with([
    ...cmsReadTools(),
    ...cmsWriteTools(),
]);

it('refuses every tool when the user lacks its CMS permission', function (string $tool, CmsPermission $permission, Closure $arguments): void {
    $others = array_values(array_filter(CmsPermission::cases(), fn (CmsPermission $case): bool => $case !== $permission));
    $input = $arguments();
    $before = cmsContentCounts();

    CmsTestServer::actingAs(cmsUser(...$others))
        ->tool($tool, $input)
        ->assertHasErrors([sprintf('lacks the CMS permission "%s"', $permission->value)]);

    expect(cmsContentCounts())->toBe($before);
})->with([
    ...cmsReadTools(),
    ...cmsWriteTools(),
]);

it('refuses every write tool unless the grant level is full, and changes nothing', function (string $tool, CmsPermission $permission, Closure $arguments, ?string $level): void {
    $input = $arguments();
    $before = cmsContentCounts();

    hubGrant($level);

    CmsTestServer::actingAs(cmsUser(...CmsPermission::cases()))
        ->tool($tool, $input)
        ->assertHasErrors(['grant for this app is read-only']);

    expect(cmsContentCounts())->toBe($before);
})->with(cmsWriteTools())->with([
    'no header' => [null],
    'read' => ['read'],
    'unknown value' => ['admin'],
]);

it('runs read tools under a read grant', function (string $tool, CmsPermission $permission, Closure $arguments): void {
    $input = $arguments();

    hubGrant('read');

    CmsTestServer::actingAs(cmsUser($permission))
        ->tool($tool, $input)
        ->assertOk();
})->with(cmsReadTools());

it('runs write tools under a full grant', function (string $tool, CmsPermission $permission, Closure $arguments): void {
    $input = $arguments();

    hubGrant('full');

    CmsTestServer::actingAs(cmsUser($permission, CmsPermission::PagesEdit))
        ->tool($tool, $input)
        ->assertOk();
})->with(cmsWriteTools());

it('reads the grant level from the hub request over HTTP', function (): void {
    Mcp::web('/internal/mcp', CmsTestServer::class);
    Route::getRoutes()->refreshNameLookups();

    $this->actingAs(cmsUser(CmsPermission::PagesEdit));

    $call = fn (?string $level) => $this->postJson('/internal/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'page_draft_create',
            'arguments' => [
                'title' => 'Via de hub',
            ],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        ...(is_null($level) ? [] : [CmsTools::GRANT_LEVEL_HEADER => $level]),
    ]);

    $call(null)
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    $call('read')
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect(Page::query()
        ->count())->toBe(0);

    $call('full')
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(Page::query()
        ->sole()
        ->status)->toBe(PageStatus::Draft);
});

it('lists every CMS tool over HTTP without an acting user', function (): void {
    Mcp::web('/internal/mcp', CmsTestServer::class);
    Route::getRoutes()->refreshNameLookups();

    $this->postJson('/internal/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], [
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertOk()
        ->assertJsonCount(count(CmsTools::TOOLS), 'result.tools');
});

it('offers the schema types as an enum, and no enum when none are configured', function (array $types, ?array $enum): void {
    config()->set('cms.schema_types', $types);

    Mcp::web('/internal/mcp', CmsTestServer::class);
    Route::getRoutes()->refreshNameLookups();

    $tools = collect($this->postJson('/internal/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [],
    ], [
        'Accept' => 'application/json, text/event-stream',
    ])->assertOk()->json('result.tools'))->keyBy('name');

    expect($tools['page_update']['inputSchema']['properties']['schema_type']['enum'] ?? null)->toBe($enum)
        ->and($tools['page_draft_create']['inputSchema']['properties']['schema_type']['enum'] ?? null)->toBe($enum);
})->with([
    'configured' => [['WebPage', 'AboutPage'], ['WebPage', 'AboutPage']],
    'none' => [[], null],
]);
