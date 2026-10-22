<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\RecordRevision;
use J87NL\Cms\Actions\RestoreRevision;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Mcp\Tools\BlockAddLiveTool;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveLiveTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderLiveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateLiveTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateTool;
use J87NL\Cms\Mcp\Tools\PageGetTool;
use J87NL\Cms\Mcp\Tools\PagePublishTool;
use J87NL\Cms\Mcp\Tools\PagesListTool;
use J87NL\Cms\Mcp\Tools\PageUnpublishTool;
use J87NL\Cms\Mcp\Tools\PageUpdateLiveTool;
use J87NL\Cms\Mcp\Tools\PageUpdateTool;
use J87NL\Cms\Mcp\Tools\RevisionDiscardTool;
use J87NL\Cms\Media\MediaUsage;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

/**
 * @return array<string, array{class-string, class-string, Closure(Page, Block, Block): array<string, mixed>, Closure(Block, Block): list<array{int, mixed}>}>
 */
function cmsPageWriteModes(): array
{
    return [
        'page_update' => [
            PageUpdateTool::class,
            PageUpdateLiveTool::class,
            fn (Page $page): array => ['page_id' => $page->id, 'title' => 'Anders'],
            fn (Block $first, Block $second): array => [[$first->id, '<p>Een</p>'], [$second->id, '<p>Twee</p>']],
        ],
        'block_add' => [
            BlockAddTool::class,
            BlockAddLiveTool::class,
            fn (Page $page): array => ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Drie</p>']],
            fn (Block $first, Block $second): array => [[$first->id, '<p>Een</p>'], [$second->id, '<p>Twee</p>'], [0, '<p>Drie</p>']],
        ],
        'block_update' => [
            BlockUpdateTool::class,
            BlockUpdateLiveTool::class,
            fn (Page $page, Block $first): array => ['block_id' => $first->id, 'data' => ['html' => '<p>Anders</p>']],
            fn (Block $first, Block $second): array => [[$first->id, '<p>Anders</p>'], [$second->id, '<p>Twee</p>']],
        ],
        'block_remove' => [
            BlockRemoveTool::class,
            BlockRemoveLiveTool::class,
            fn (Page $page, Block $first): array => ['block_id' => $first->id],
            fn (Block $first, Block $second): array => [[$second->id, '<p>Twee</p>']],
        ],
        'blocks_reorder' => [
            BlocksReorderTool::class,
            BlocksReorderLiveTool::class,
            fn (Page $page, Block $first, Block $second): array => ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]],
            fn (Block $first, Block $second): array => [[$second->id, '<p>Twee</p>'], [$first->id, '<p>Een</p>']],
        ],
    ];
}

/**
 * @return list<array{int, mixed}>
 */
function liveBlockSummary(Page $page): array
{
    return array_values(Block::query()
        ->where('page_id', $page->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get()
        ->map(fn (Block $block): array => [
            $block->id,
            $block->data['html'] ?? null,
        ])
        ->all());
}

/**
 * @param  list<array{int, mixed}>  $summary
 * @return list<array{int, mixed}>
 */
function withoutNewIds(array $summary, array $knownIds): array
{
    return array_map(fn (array $block): array => [
        in_array($block[0], $knownIds, true) ? $block[0] : 0,
        $block[1],
    ], $summary);
}

function publisher(): Authorizable
{
    return cmsUser(CmsPermission::PagesEdit, CmsPermission::PagesPublish);
}

it('changes the live page, records a revision and flushes the cache in live mode', function (string $draftTool, string $liveTool, Closure $arguments, Closure $expected): void {
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);
    $revisions = $page->revisions()->count();
    $version = app(PageCache::class)->version();

    CmsTestServer::actingAs(publisher())
        ->tool($liveTool, $arguments($page, $first, $second))
        ->assertOk();

    expect(withoutNewIds(liveBlockSummary($page), [$first->id, $second->id]))->toBe($expected($first, $second))
        ->and($page->revisions()->count())->toBe($revisions + 1)
        ->and(app(PageCache::class)->version())->toBeGreaterThan($version)
        ->and($page->pendingRevision()->exists())->toBeFalse();
})->with(cmsPageWriteModes());

it('keeps the live page and the cache as they are in draft mode on a published page', function (string $draftTool, string $liveTool, Closure $arguments, Closure $expected): void {
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);
    $revisions = $page->revisions()->count();
    $version = app(PageCache::class)->version();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool($draftTool, $arguments($page, $first, $second))
        ->assertOk();

    expect(liveBlockSummary($page))->toBe([[$first->id, '<p>Een</p>'], [$second->id, '<p>Twee</p>']])
        ->and($page->refresh()->title)->toBe('Live')
        ->and($page->revisions()->count())->toBe($revisions)
        ->and(app(PageCache::class)->version())->toBe($version)
        ->and(withoutNewIds(pendingBlockSummary($page->pendingRevision()->sole()), [$first->id, $second->id]))->toBe($expected($first, $second));
})->with(cmsPageWriteModes());

it('refuses live mode without the publish permission', function (string $draftTool, string $liveTool, Closure $arguments, Closure $expected): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool($liveTool, $arguments($page, $first, $second))
        ->assertHasErrors(['lacks the CMS permission "cms.pages.publish"']);

    expect(liveBlockSummary($page))->toBe([[$first->id, '<p>Een</p>'], [$second->id, '<p>Twee</p>']])
        ->and($page->refresh()->title)->toBe('Live');
})->with(cmsPageWriteModes());

it('refuses live mode and publication without the edit permission', function (string $tool, Closure $arguments): void {
    $page = pendingPage();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesPublish))
        ->tool($tool, $arguments($page))
        ->assertHasErrors(['lacks the CMS permission "cms.pages.edit"']);

    expect($page->refresh()->title)->toBe('Live')
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->pendingRevision()->exists())->toBeTrue();
})->with([
    'page_update_live' => [PageUpdateLiveTool::class, fn (Page $page): array => ['page_id' => $page->id, 'title' => 'Anders']],
    'page_publish' => [PagePublishTool::class, fn (Page $page): array => ['page_id' => $page->id]],
    'page_unpublish' => [PageUnpublishTool::class, fn (Page $page): array => ['page_id' => $page->id]],
    'revision_discard' => [RevisionDiscardTool::class, fn (Page $page): array => ['page_id' => $page->id]],
]);

it('lets an editor without publish rights use the draft tools on a published page, despite the admin write gate', function (): void {
    $page = makePage(['title' => 'Live']);

    Gate::define(CmsTools::PAGE_WRITE_ABILITY, fn (Authorizable $user, Page $page): bool => ! $page->isPublished() || $user->can(CmsPermission::PagesPublish->value));

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Voorstel'])
        ->assertOk();

    expect($page->pendingRevision()->sole()->snapshot['page']['title'])->toBe('Voorstel');
});

it('still applies the write gate to live mode', function (): void {
    $page = makePage(['title' => 'Live']);

    Gate::define(CmsTools::PAGE_WRITE_ABILITY, fn (Authorizable $user, Page $page): bool => false);

    CmsTestServer::actingAs(publisher())
        ->tool(PageUpdateLiveTool::class, ['page_id' => $page->id, 'title' => 'Anders'])
        ->assertHasErrors(['You may not change page']);

    expect($page->refresh()->title)->toBe('Live');
});

it('collects several draft changes in one pending revision', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $editor = cmsUser(CmsPermission::PagesEdit);

    CmsTestServer::actingAs($editor)
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Nieuw'])
        ->assertOk();

    CmsTestServer::actingAs($editor)
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Twee</p>']])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('block.id', -1)
            ->where('pending_revision.page.title', 'Nieuw')
            ->etc());

    CmsTestServer::actingAs($editor)
        ->tool(BlockUpdateTool::class, ['page_id' => $page->id, 'block_id' => -1, 'data' => ['html' => '<p>Drie</p>']])
        ->assertOk();

    CmsTestServer::actingAs($editor)
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [-1, $block->id]])
        ->assertOk();

    $pending = Revision::query()
        ->pending()
        ->where('page_id', $page->id)
        ->sole();

    expect($pending->created_by)->toBe($editor->id)
        ->and($pending->snapshot['page']['title'])->toBe('Nieuw')
        ->and(pendingBlockSummary($pending))->toBe([[-1, '<p>Drie</p>'], [$block->id, '<p>Een</p>']]);

    CmsTestServer::actingAs($editor)
        ->tool(BlockRemoveTool::class, ['page_id' => $page->id, 'block_id' => -1])
        ->assertOk();

    expect(pendingBlockSummary($pending->refresh()))->toBe([[$block->id, '<p>Een</p>']]);
});

it('reports a pending block id that does not exist', function (): void {
    $page = makePage(['title' => 'Live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => -3, 'data' => ['html' => '<p>X</p>']])
        ->assertHasErrors(['Block -3 was not found']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockRemoveTool::class, ['page_id' => $page->id, 'block_id' => -3])
        ->assertHasErrors(['Block -3 was not found']);

    expect($page->pendingRevision()->exists())->toBeFalse();
});

it('validates draft block data with the rules of its type', function (): void {
    $page = makePage(['title' => 'Live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, [
            'page_id' => $page->id,
            'type' => 'call_to_action',
            'data' => ['title' => 'Kom langs', 'button_label' => 'Klik', 'url' => 'javascript:alert(1)'],
        ])
        ->assertHasErrors([__('cms::validation.unsafe_link')]);

    expect($page->pendingRevision()->exists())->toBeFalse();
});

it('shows the pending revision in page_get and pages_list', function (): void {
    $page = pendingPage();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $page->id])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('title', 'Live')
            ->where('has_pending_revision', true)
            ->where('pending_revision.page.title', 'Live concept')
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PagesListTool::class)
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pages.0.has_pending_revision', true)
            ->etc());
});

it('keeps a pending revision off the public page until it is published', function (): void {
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Oude tekst</p>']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>Verdwijnt</p>']);
    $editor = cmsUser(CmsPermission::PagesEdit);

    $this->get('/live')->assertOk();

    hubGrant(CmsTools::FULL_GRANT);

    CmsTestServer::actingAs($editor)
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Nieuwe titel'])
        ->assertOk();

    CmsTestServer::actingAs($editor)
        ->tool(BlockUpdateTool::class, ['block_id' => $first->id, 'data' => ['html' => '<p>Nieuwe tekst</p>']])
        ->assertOk();

    CmsTestServer::actingAs($editor)
        ->tool(BlockRemoveTool::class, ['block_id' => $removed->id])
        ->assertOk();

    CmsTestServer::actingAs($editor)
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Erbij</p>']])
        ->assertOk();

    $this->get('/live')
        ->assertOk()
        ->assertSee('Oude tekst')
        ->assertSee('Verdwijnt')
        ->assertDontSee('Nieuwe tekst')
        ->assertDontSee('Erbij')
        ->assertDontSee('Nieuwe titel');

    $version = app(PageCache::class)->version();

    hubGrant(CmsTools::FULL_GRANT);

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(app(PageCache::class)->version())->toBeGreaterThan($version)
        ->and($page->pendingRevision()->exists())->toBeFalse()
        ->and($first->refresh()->data['html'])->toBe('<p>Nieuwe tekst</p>')
        ->and(Block::query()
            ->whereKey($removed->id)
            ->exists())->toBeFalse();

    $this->get('/live')
        ->assertOk()
        ->assertSee('Nieuwe titel')
        ->assertSee('Nieuwe tekst')
        ->assertSee('Erbij')
        ->assertDontSee('Verdwijnt');
});

it('records the published content as the latest revision', function (): void {
    $page = pendingPage();
    $revisions = $page->revisions()->count();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->revisions()->count())->toBe($revisions + 1)
        ->and($page->revisions()->first()->snapshot['page']['title'])->toBe('Live concept');
});

it('publishes a draft page', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Concept', 'slug' => 'concept'], PageStatus::Draft);
    addBlock($page, 'rich_text', ['html' => '<p>Klaar</p>']);

    $this->get('/concept')->assertNotFound();

    hubGrant(CmsTools::FULL_GRANT);

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk()
        ->assertSee('is published at /concept');

    expect($page->refresh()->status)->toBe(PageStatus::Published);

    $this->get('/concept')
        ->assertOk()
        ->assertSee('Klaar');
});

it('refuses to publish a published page without a pending revision', function (): void {
    $page = makePage(['title' => 'Live']);
    $version = app(PageCache::class)->version();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertHasErrors([__('cms::validation.nothing_to_publish')]);

    expect(app(PageCache::class)->version())->toBe($version);
});

it('refuses to publish a pending revision whose address is taken', function (): void {
    makePage(['title' => 'Bezet', 'slug' => 'bezet']);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'slug' => 'bezet'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertHasErrors(['/bezet']);

    expect($page->refresh()->slug)->toBe('live')
        ->and($page->pendingRevision()->exists())->toBeTrue();
});

it('takes a page offline and flushes the cache', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $version = app(PageCache::class)->version();

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and(app(PageCache::class)->version())->toBeGreaterThan($version);

    $this->get('/live')->assertNotFound();
});

it('refuses to take the home page or a page that is not published offline', function (): void {
    $home = makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $draft = makePage(['title' => 'Concept', 'slug' => 'concept'], PageStatus::Draft);

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $home->id])
        ->assertHasErrors([__('cms::validation.unpublish_home')]);

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $draft->id])
        ->assertHasErrors(['is not published']);

    expect($home->refresh()->status)->toBe(PageStatus::Published)
        ->and($draft->refresh()->status)->toBe(PageStatus::Draft);
});

it('discards a pending revision without touching the live page or the cache', function (): void {
    $page = pendingPage();
    $version = app(PageCache::class)->version();

    CmsTestServer::actingAs(publisher())
        ->tool(RevisionDiscardTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->pendingRevision()->exists())->toBeFalse()
        ->and($page->refresh()->title)->toBe('Live')
        ->and(app(PageCache::class)->version())->toBe($version);

    CmsTestServer::actingAs(publisher())
        ->tool(RevisionDiscardTool::class, ['page_id' => $page->id])
        ->assertHasErrors(['has no pending revision']);
});

it('keeps pages with a planned publication read-only for every new tool', function (string $tool, Closure $arguments): void {
    $page = makePage(['title' => 'Gepland'], PageStatus::Draft);
    $page->publish_at = now()->addDay();
    $page->save();
    $block = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);

    CmsTestServer::actingAs(publisher())
        ->tool($tool, $arguments($page, $block))
        ->assertHasErrors(['is scheduled to be published']);

    expect($page->refresh()->title)->toBe('Gepland')
        ->and($page->status)->toBe(PageStatus::Draft)
        ->and($block->refresh()->data['html'])->toBe('<p>Een</p>');
})->with([
    'page_update_live' => [PageUpdateLiveTool::class, fn (Page $page): array => ['page_id' => $page->id, 'title' => 'Anders']],
    'block_add_live' => [BlockAddLiveTool::class, fn (Page $page): array => ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Twee</p>']]],
    'block_update_live' => [BlockUpdateLiveTool::class, fn (Page $page, Block $block): array => ['block_id' => $block->id, 'data' => ['html' => '<p>Anders</p>']]],
    'block_remove_live' => [BlockRemoveLiveTool::class, fn (Page $page, Block $block): array => ['block_id' => $block->id]],
    'blocks_reorder_live' => [BlocksReorderLiveTool::class, fn (Page $page, Block $block): array => ['page_id' => $page->id, 'block_ids' => [$block->id]]],
    'page_publish' => [PagePublishTool::class, fn (Page $page): array => ['page_id' => $page->id]],
    'page_unpublish' => [PageUnpublishTool::class, fn (Page $page): array => ['page_id' => $page->id]],
    'revision_discard' => [RevisionDiscardTool::class, fn (Page $page): array => ['page_id' => $page->id]],
]);

it('never restores a pending revision as history', function (): void {
    $page = pendingPage();

    expect(fn () => app(RestoreRevision::class)->handle($page->pendingRevision()->sole(), null))
        ->toThrow(ValidationException::class);

    expect($page->refresh()->title)->toBe('Live');
});

it('keeps the pending revision when the history is pruned', function (): void {
    config()->set('cms.revisions.keep', 2);

    $page = pendingPage();

    foreach (range(1, 4) as $round) {
        app(RecordRevision::class)->handle($page, null);
    }

    expect($page->revisions()->count())->toBe(2)
        ->and($page->pendingRevision()->exists())->toBeTrue();
});

it('counts media in a pending revision as in use', function (): void {
    $asset = uploadImage();
    $page = makePage(['title' => 'Live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'image', 'data' => ['media_id' => $asset->id]])
        ->assertOk();

    expect(app(MediaUsage::class)->count($asset))->toBe(1);
});

it('checks the write gate before publishing, taking offline or discarding', function (string $tool): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = pendingPage();

    Gate::define(CmsTools::PAGE_WRITE_ABILITY, fn (Authorizable $user, Page $page): bool => $page->title !== 'Live');

    CmsTestServer::actingAs(publisher())
        ->tool($tool, ['page_id' => $page->id])
        ->assertHasErrors(['You may not change page']);

    expect($page->refresh()->title)->toBe('Live')
        ->and($page->status)->toBe(PageStatus::Published)
        ->and($page->pendingRevision()->exists())->toBeTrue();
})->with([
    'page_publish' => [PagePublishTool::class],
    'page_unpublish' => [PageUnpublishTool::class],
    'revision_discard' => [RevisionDiscardTool::class],
]);

it('lets a proposal on a gated page wait for someone the gate allows', function (): void {
    $page = makePage(['title' => 'Live']);

    Gate::define(CmsTools::PAGE_WRITE_ABILITY, fn (Authorizable $user, Page $page): bool => false);

    CmsTestServer::actingAs(publisher())
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertHasErrors(['You may not change page']);

    expect($page->refresh()->title)->toBe('Live');
});

it('applies the pending revision to a page that is taken offline, so later edits are not overwritten', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->title)->toBe('Voorstel')
        ->and($page->status)->toBe(PageStatus::Unpublished)
        ->and($page->pendingRevision()->exists())->toBeFalse();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Nieuwste'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->title)->toBe('Nieuwste')
        ->and($page->status)->toBe(PageStatus::Published);
});

it('publishes only what the pending revision changed, and keeps later live changes', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live', 'meta_title' => 'Oud']);
    $changed = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $removedLive = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => $changed->id, 'data' => ['html' => '<p>A nieuw</p>']])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'meta_title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockAddLiveTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Live erbij</p>']])
        ->assertOk()
        ->assertSee('also has a pending revision');

    CmsTestServer::actingAs(publisher())
        ->tool(BlockRemoveLiveTool::class, ['block_id' => $removedLive->id])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PageUpdateLiveTool::class, ['page_id' => $page->id, 'slug' => 'verhuisd'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->slug)->toBe('verhuisd')
        ->and($page->meta_title)->toBe('Voorstel')
        ->and(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
            '<p>A nieuw</p>',
            '<p>Live erbij</p>',
        ])
        ->and($changed->refresh()->data['html'])->toBe('<p>A nieuw</p>');
});

it('applies a new block order from the pending revision and keeps live-only blocks after it', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>2</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockAddLiveTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>live</p>']])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>2</p>',
        '<p>1</p>',
        '<p>live</p>',
    ]);
});

it('lets the draft tools change and remove a block added live after the pending revision started', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockAddLiveTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>live 1</p>']])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockAddLiveTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>live 2</p>']])
        ->assertOk();

    [$changed, $removed] = Block::query()
        ->where('page_id', $page->id)
        ->whereKeyNot($first->id)
        ->orderBy('id')
        ->get()
        ->all();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $page->id])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pending_revision.blocks.1.id', $changed->id)
            ->where('pending_revision.blocks.2.id', $removed->id)
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => $changed->id, 'data' => ['html' => '<p>live 1 nieuw</p>']])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockRemoveTool::class, ['block_id' => $removed->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>1</p>',
        '<p>live 1</p>',
        '<p>live 2</p>',
    ]);

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->title)->toBe('Voorstel')
        ->and(liveBlockSummary($page))->toBe([
            [$first->id, '<p>1</p>'],
            [$changed->id, '<p>live 1 nieuw</p>'],
        ]);
});

it('publishes a block added live after a pending reorder where page_get shows it', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);

    foreach (['<p>P</p>', '<p>Q</p>'] as $html) {
        CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
            ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => $html]])
            ->assertOk();
    }

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [-1, -2, $first->id, $second->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockAddLiveTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>X</p>']])
        ->assertOk();

    $expected = [
        '<p>P</p>',
        '<p>Q</p>',
        '<p>A</p>',
        '<p>X</p>',
        '<p>B</p>',
    ];

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $page->id])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pending_revision.blocks', fn (mixed $blocks): bool => collect($blocks)->pluck('data.html')->all() === $expected)
            ->etc());

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe($expected);
});

it('keeps no pending revision for a draft that changes nothing', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>2</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [$first->id, $second->id]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pending_revision', null)
            ->etc());

    expect($page->pendingRevision()->exists())->toBeFalse();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Anders'])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Live'])
        ->assertOk();

    expect($page->pendingRevision()->exists())->toBeFalse();
});

it('never reuses the id of a removed pending block', function (): void {
    $page = makePage(['title' => 'Live']);
    $editor = cmsUser(CmsPermission::PagesEdit);
    $add = fn (): mixed => CmsTestServer::actingAs($editor)
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>x</p>']])
        ->assertOk();

    $add();
    $add();

    CmsTestServer::actingAs($editor)
        ->tool(BlockRemoveTool::class, ['page_id' => $page->id, 'block_id' => -2])
        ->assertOk();

    $add()->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
        ->where('block.id', -3)
        ->etc());
});

it('records who changed the pending revision last', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = cmsUser(CmsPermission::PagesEdit);
    $second = cmsUser(CmsPermission::PagesEdit);

    CmsTestServer::actingAs($first)
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Een'])
        ->assertOk();

    CmsTestServer::actingAs($second)
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Twee'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pending_revision.created_by', $first->id)
            ->where('pending_revision.edited_by', $second->id)
            ->etc());
});

it('counts an image once when the live page and its pending revision both use it', function (): void {
    $asset = uploadImage();
    $shareImage = uploadImage();
    $page = makePage(['title' => 'Live']);
    addBlock($page, 'image', ['media_id' => $asset->id]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'og_image_id' => $shareImage->id])
        ->assertOk();

    expect(app(MediaUsage::class)->count($asset))->toBe(1)
        ->and(app(MediaUsage::class)->count($shareImage))->toBe(1);
});

it('keeps a live reorder when the pending revision only adds a block', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>2</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>nieuw</p>']])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlocksReorderLiveTool::class, ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>2</p>',
        '<p>1</p>',
        '<p>nieuw</p>',
    ]);
});

it('places a new pending block after the block it follows in the pending revision', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>2</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>nieuw</p>']])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [$first->id, -1, $second->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>1</p>',
        '<p>nieuw</p>',
        '<p>2</p>',
    ]);
});

it('lets the pending revision win for a block that one side changed and the other removed', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $removedInDraft = addBlock($page, 'rich_text', ['html' => '<p>weg in voorstel</p>']);
    $removedLive = addBlock($page, 'rich_text', ['html' => '<p>weg live</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockRemoveTool::class, ['block_id' => $removedInDraft->id])
        ->assertOk();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => $removedLive->id, 'data' => ['html' => '<p>aangepast in voorstel</p>']])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockUpdateLiveTool::class, ['block_id' => $removedInDraft->id, 'data' => ['html' => '<p>aangepast live</p>']])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlockRemoveLiveTool::class, ['block_id' => $removedLive->id])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>aangepast in voorstel</p>',
    ]);
});

it('takes a page offline even when its pending revision asks for an address that is taken', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'slug' => 'bezet', 'title' => 'Voorstel'])
        ->assertOk();

    makePage(['title' => 'Bezet', 'slug' => 'bezet']);

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and($page->slug)->toBe('live')
        ->and($page->title)->toBe('Voorstel')
        ->and($page->pendingRevision()->exists())->toBeFalse();
});

it('treats equal values of another type as no change', function (): void {
    $asset = uploadImage();
    $page = makePage(['title' => 'Live', 'noindex' => true, 'og_image_id' => $asset->id]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'noindex' => 1, 'og_image_id' => (string) $asset->id])
        ->assertOk();

    expect($page->pendingRevision()->exists())->toBeFalse();
});

it('keeps new pending blocks at the top when the live page was reordered', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>a</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>b</p>']);
    $editor = cmsUser(CmsPermission::PagesEdit);

    foreach (['<p>n1</p>', '<p>n2</p>'] as $html) {
        CmsTestServer::actingAs($editor)
            ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => $html]])
            ->assertOk();
    }

    CmsTestServer::actingAs($editor)
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [-1, -2, $first->id, $second->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(BlocksReorderLiveTool::class, ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PagePublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect(array_map(fn (array $block): mixed => $block[1], liveBlockSummary($page)))->toBe([
        '<p>n1</p>',
        '<p>n2</p>',
        '<p>b</p>',
        '<p>a</p>',
    ]);
});

it('treats stored block data with its keys sorted, as MySQL stores JSON, as no change', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'call_to_action', ['title' => 'Kom', 'button_label' => 'Klik', 'url' => '/contact']);
    $data = $block->refresh()->data;
    $sorted = $data;

    ksort($sorted);

    Block::query()
        ->whereKey($block->id)
        ->update(['data' => json_encode($sorted)]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => $block->id, 'data' => $data])
        ->assertOk();

    expect(array_keys($sorted))->not->toBe(array_keys($data))
        ->and($page->pendingRevision()->exists())->toBeFalse();
});

it('logs a pending parent change that cannot be applied when a page goes offline', function (): void {
    Log::spy();

    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $child = makePage(['title' => 'Kind', 'slug' => 'kind', 'parent_id' => $page->id]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'parent_id' => $child->id, 'title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and($page->parent_id)->toBeNull()
        ->and($page->title)->toBe('Voorstel');

    Log::shouldHaveReceived('warning')->once();
});

it('takes a page with a legacy slug offline when its pending address is taken', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    Page::query()
        ->whereKey($page->id)
        ->update(['slug' => 'Over-Ons']);
    makePage(['title' => 'Bezet', 'slug' => 'bezet']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'slug' => 'bezet', 'title' => 'Voorstel'])
        ->assertOk();

    CmsTestServer::actingAs(publisher())
        ->tool(PageUnpublishTool::class, ['page_id' => $page->id])
        ->assertOk();

    expect($page->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and($page->slug)->toBe('Over-Ons')
        ->and($page->title)->toBe('Voorstel');
});
