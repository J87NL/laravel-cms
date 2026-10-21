<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Mcp\Tools\BlockRemoveTool;
use J87NL\Cms\Mcp\Tools\BlocksReorderTool;
use J87NL\Cms\Mcp\Tools\BlockUpdateTool;
use J87NL\Cms\Mcp\Tools\PageDraftCreateTool;
use J87NL\Cms\Mcp\Tools\PageGetTool;
use J87NL\Cms\Mcp\Tools\PagesListTool;
use J87NL\Cms\Mcp\Tools\PageUpdateTool;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

it('lists the page tree with status, home and public flags', function (): void {
    $home = makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $draft = makePage(['title' => 'Concept', 'slug' => 'concept'], PageStatus::Draft);
    makePage(['title' => 'Onder concept', 'slug' => 'onder', 'parent_id' => $draft->id]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PagesListTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('pages', 2)
            ->where('pages.0.id', $home->id)
            ->where('pages.0.is_home', true)
            ->where('pages.0.is_public', true)
            ->where('pages.1.status', 'draft')
            ->where('pages.1.is_public', false)
            ->where('pages.1.children.0.path', '/concept/onder')
            ->where('pages.1.children.0.status', 'published')
            ->where('pages.1.children.0.is_public', false)
            ->etc());
});

it('returns a page with its blocks in order', function (): void {
    $page = makePage(['title' => 'Over ons', 'meta_description' => 'Wie wij zijn']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'call_to_action', ['title' => 'Bel ons', 'button_label' => 'Bellen', 'url' => 'tel:0123']);

    Block::query()
        ->whereKey($first->id)
        ->update(['sort_order' => 9]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $page->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('title', 'Over ons')
            ->where('meta_description', 'Wie wij zijn')
            ->where('writable', false)
            ->where('blocks.0.id', $second->id)
            ->where('blocks.0.type', 'call_to_action')
            ->where('blocks.0.data.title', 'Bel ons')
            ->where('blocks.1.id', $first->id)
            ->where('blocks.1.data.html', '<p>Een</p>')
            ->etc());
});

it('reports an unknown page with a hint', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => 999])
        ->assertHasErrors(['Page 999 was not found. Use pages_list']);
});

it('creates a draft and never publishes it, whatever is passed', function (): void {
    $user = cmsUser(CmsPermission::PagesEdit, CmsPermission::PagesPublish);

    CmsTestServer::actingAs($user)
        ->tool(PageDraftCreateTool::class, [
            'title' => 'Zomerfeest',
            'meta_description' => 'Het zomerfeest',
            'status' => 'published',
            'is_home' => true,
            'publish_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertOk()
        ->assertSee('created at /zomerfeest');

    $page = Page::query()
        ->sole();

    expect($page->status)->toBe(PageStatus::Draft)
        ->and($page->is_home)->toBeFalse()
        ->and($page->publish_at)->toBeNull()
        ->and($page->slug)->toBe('zomerfeest')
        ->and($page->meta_description)->toBe('Het zomerfeest')
        ->and(Revision::query()
            ->where('page_id', $page->id)
            ->value('created_by'))->toBe($user->id);
});

it('returns validation failures of a new draft as an error', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageDraftCreateTool::class, ['title' => 'Zomer', 'canonical_url' => 'javascript:alert(1)'])
        ->assertHasErrors(['canonical url']);

    makePage(['title' => 'Zomer']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageDraftCreateTool::class, ['title' => 'Zomer'])
        ->assertHasErrors(['/zomer']);

    expect(Page::query()
        ->count())->toBe(1);
});

it('updates only the passed fields of a draft or unpublished page', function (PageStatus $status): void {
    $page = makePage(['title' => 'Oud', 'slug' => 'pagina', 'meta_title' => 'Blijft'], $status);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Nieuw', 'status' => 'published'])
        ->assertOk();

    $page->refresh();

    expect($page->title)->toBe('Nieuw')
        ->and($page->slug)->toBe('pagina')
        ->and($page->meta_title)->toBe('Blijft')
        ->and($page->status)->toBe($status);
})->with([
    'draft' => [PageStatus::Draft],
    'unpublished' => [PageStatus::Unpublished],
]);

it('accepts only a supported schema type', function (): void {
    $page = makePage(['title' => 'Over ons'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'schema_type' => 'Person'])
        ->assertHasErrors(['schema type']);

    expect($page->refresh()->schema_type)->toBeNull();

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'schema_type' => 'AboutPage'])
        ->assertOk();

    expect($page->refresh()->schema_type)->toBe('AboutPage');
});

it('refuses a canonical url longer than its column', function (): void {
    $page = makePage(['title' => 'Lang'], PageStatus::Draft);
    $url = sprintf('https://example.org/%s', str_repeat('a', 235));

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'canonical_url' => sprintf('%sa', $url)])
        ->assertHasErrors(['canonical url']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'canonical_url' => $url])
        ->assertOk();

    expect($page->refresh()->canonical_url)->toBe($url);
});

it('refuses to update a published page, also for a publisher', function (): void {
    $page = makePage(['title' => 'Live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit, CmsPermission::PagesPublish))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Anders'])
        ->assertHasErrors(['is published', 'CMS admin']);

    expect($page->refresh()->title)->toBe('Live');
});

it('respects the page write gate of the admin when it is defined', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    Gate::define(CmsTools::PAGE_WRITE_ABILITY, fn (Authorizable $user, Page $page): bool => false);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'title' => 'Anders'])
        ->assertHasErrors(['You may not change page']);

    expect($page->refresh()->title)->toBe('Concept');
});

it('asks for a field when page_update gets nothing to change', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageUpdateTool::class, ['page_id' => $page->id, 'status' => 'published'])
        ->assertHasErrors(['Nothing to change']);

    expect($page->refresh()->status)->toBe(PageStatus::Draft);
});

it('adds a sanitized block at the end of a draft page and records a revision', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);
    addBlock($page, 'rich_text', ['html' => '<p>Eerst</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, [
            'page_id' => $page->id,
            'type' => 'rich_text',
            'data' => ['html' => '<p>Hallo<script>alert(1)</script></p>'],
        ])
        ->assertOk();

    $block = Block::query()
        ->where('page_id', $page->id)
        ->orderByDesc('sort_order')
        ->first();

    expect($block->data['html'])->toBe('<p>Hallo</p>')
        ->and($block->sort_order)->toBe(2)
        ->and(Revision::query()
            ->where('page_id', $page->id)
            ->count())->toBe(3);
});

it('refuses an unknown block type and lists the known ones', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'carousel', 'data' => []])
        ->assertHasErrors(['The block type "carousel" does not exist', 'rich_text']);

    expect(Block::query()
        ->count())->toBe(0);
});

it('validates block data with the rules of its type', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, [
            'page_id' => $page->id,
            'type' => 'call_to_action',
            'data' => ['title' => 'Kom langs', 'button_label' => 'Klik', 'url' => 'javascript:alert(1)'],
        ])
        ->assertHasErrors([__('cms::validation.unsafe_link')]);

    expect(Block::query()
        ->count())->toBe(0);
});

it('refuses block writes on a published page', function (string $tool, Closure $arguments): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit, CmsPermission::PagesPublish))
        ->tool($tool, $arguments($page, $first, $second))
        ->assertHasErrors(['is published']);

    expect(pageBlockIds($page))->toBe([$first->id, $second->id])
        ->and($first->refresh()->data['html'])->toBe('<p>Een</p>');
})->with([
    'block_add' => [BlockAddTool::class, fn (Page $page): array => ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Drie</p>']]],
    'block_update' => [BlockUpdateTool::class, fn (Page $page, Block $first): array => ['block_id' => $first->id, 'data' => ['html' => '<p>Anders</p>']]],
    'block_remove' => [BlockRemoveTool::class, fn (Page $page, Block $first): array => ['block_id' => $first->id]],
    'blocks_reorder' => [BlocksReorderTool::class, fn (Page $page, Block $first, Block $second): array => ['page_id' => $page->id, 'block_ids' => [$second->id, $first->id]]],
]);

it('replaces the data of a block on a draft page', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);
    $block = addBlock($page, 'call_to_action', ['title' => 'Oud', 'button_label' => 'Klik', 'url' => '/oud']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockUpdateTool::class, ['block_id' => $block->id, 'data' => ['title' => ' Nieuw ', 'button_label' => 'Klik', 'url' => '/nieuw']])
        ->assertOk();

    expect($block->refresh()->data)->toMatchArray(['title' => 'Nieuw', 'url' => '/nieuw']);
});

it('removes a block from a draft page and keeps it in the revisions', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);
    $block = addBlock($page, 'rich_text', ['html' => '<p>Weg</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockRemoveTool::class, ['block_id' => $block->id])
        ->assertOk();

    $revisions = Revision::query()
        ->where('page_id', $page->id)
        ->orderBy('id')
        ->get();

    expect(Block::query()
        ->count())->toBe(0)
        ->and($revisions->last()->snapshot['blocks'])->toBe([])
        ->and($revisions->get($revisions->count() - 2)->snapshot['blocks'][0]['data']['html'])->toBe('<p>Weg</p>');
});

it('reorders the blocks of a draft page', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Unpublished);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>Drie</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [$third->id, $first->id, $second->id]])
        ->assertOk();

    expect(pageBlockIds($page))->toBe([$third->id, $first->id, $second->id]);
});

it('refuses a reorder that does not list every block of the page once', function (): void {
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);
    $other = addBlock(makePage(['title' => 'Ander'], PageStatus::Draft), 'rich_text', ['html' => '<p>Elders</p>']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlocksReorderTool::class, ['page_id' => $page->id, 'block_ids' => [$second->id, $other->id]])
        ->assertHasErrors([__('cms::validation.block_order_incomplete')]);

    expect(pageBlockIds($page))->toBe([$first->id, $second->id]);
});

it('treats a page with a planned publication as read-only', function (string $tool, Closure $arguments): void {
    $page = makePage(['title' => 'Gepland'], PageStatus::Draft);
    $page->publish_at = now()->addDay();
    $page->save();
    $block = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $before = pageBlockIds($page);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool($tool, $arguments($page, $block))
        ->assertHasErrors(['is scheduled to be published']);

    expect($page->refresh()->title)->toBe('Gepland')
        ->and(pageBlockIds($page))->toBe($before)
        ->and($block->refresh()->data['html'])->toBe('<p>Een</p>');
})->with([
    'page_update' => [PageUpdateTool::class, fn (Page $page): array => ['page_id' => $page->id, 'title' => 'Anders']],
    'block_add' => [BlockAddTool::class, fn (Page $page): array => ['page_id' => $page->id, 'type' => 'rich_text', 'data' => ['html' => '<p>Twee</p>']]],
    'block_update' => [BlockUpdateTool::class, fn (Page $page, Block $block): array => ['block_id' => $block->id, 'data' => ['html' => '<p>Anders</p>']]],
    'block_remove' => [BlockRemoveTool::class, fn (Page $page, Block $block): array => ['block_id' => $block->id]],
    'blocks_reorder' => [BlocksReorderTool::class, fn (Page $page, Block $block): array => ['page_id' => $page->id, 'block_ids' => [$block->id]]],
]);

it('marks published and scheduled pages as not writable', function (): void {
    $draft = makePage(['title' => 'Concept', 'slug' => 'concept'], PageStatus::Draft);
    $scheduled = makePage(['title' => 'Gepland', 'slug' => 'gepland'], PageStatus::Draft);
    $scheduled->publish_at = now()->addDay();
    $scheduled->save();
    makePage(['title' => 'Live', 'slug' => 'live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PagesListTool::class)
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('pages.0.writable', true)
            ->where('pages.1.writable', false)
            ->where('pages.2.writable', false)
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $scheduled->id])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('writable', false)
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(PageGetTool::class, ['page_id' => $draft->id])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('writable', true)
            ->etc());
});

it('checks the page before it validates block data', function (): void {
    $page = makePage(['title' => 'Live']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, ['page_id' => $page->id, 'type' => 'call_to_action', 'data' => []])
        ->assertHasErrors(['is published']);
});
