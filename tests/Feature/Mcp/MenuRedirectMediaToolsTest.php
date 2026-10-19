<?php

declare(strict_types=1);

use Illuminate\Testing\Fluent\AssertableJson;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Mcp\Tools\MediaListTool;
use J87NL\Cms\Mcp\Tools\MenuItemSaveTool;
use J87NL\Cms\Mcp\Tools\MenuItemsListTool;
use J87NL\Cms\Mcp\Tools\RedirectDeleteTool;
use J87NL\Cms\Mcp\Tools\RedirectSaveTool;
use J87NL\Cms\Mcp\Tools\RedirectsListTool;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

it('lists menu items per menu with their visibility', function (): void {
    $about = makePage(['title' => 'Over', 'slug' => 'over']);
    $draft = makePage(['title' => 'Concept', 'slug' => 'concept'], PageStatus::Draft);

    $menu = Menu::forKey('main');
    $menu->items()->create(['page_id' => $about->id, 'sort_order' => 1]);
    $menu->items()->create(['page_id' => $draft->id, 'sort_order' => 2]);
    Menu::forKey('footer')->items()->create(['url' => '/privacy', 'label' => 'Privacy', 'sort_order' => 1]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemsListTool::class, ['menu' => 'main'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('menus', 1)
            ->where('menus.0.key', 'main')
            ->has('menus.0.items', 2)
            ->where('menus.0.items.0.label', 'Over')
            ->where('menus.0.items.0.href', '/over')
            ->where('menus.0.items.0.is_visible', true)
            ->where('menus.0.items.1.is_visible', false)
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemsListTool::class)
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('menus', 2)
            ->where('menus.1.items.0.url', '/privacy')
            ->etc());
});

it('refuses an unknown menu key and names the known ones', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, ['menu' => 'sidebar', 'url' => '/x', 'label' => 'X'])
        ->assertHasErrors(['The menu "sidebar" does not exist. Use one of: main, footer.']);

    expect(MenuItem::query()
        ->count())->toBe(0);
});

it('adds a page item to a menu, also for a draft page, which stays hidden', function (): void {
    $draft = makePage(['title' => 'Concept', 'menu_title' => 'Binnenkort'], PageStatus::Draft);
    Menu::forKey('main')->items()->create(['url' => '/eerst', 'label' => 'Eerst', 'sort_order' => 4]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, ['menu' => 'main', 'page_id' => $draft->id])
        ->assertOk()
        ->assertSee('stays hidden on the website until the linked page is published');

    $item = MenuItem::query()
        ->where('page_id', $draft->id)
        ->sole();

    expect($item->sort_order)->toBe(5)
        ->and($item->url)->toBeNull()
        ->and($item->displayLabel())->toBe('Binnenkort')
        ->and($draft->refresh()->status)->toBe(PageStatus::Draft);
});

it('updates a menu item and places it under a top-level item', function (): void {
    $menu = Menu::forKey('main');
    $parent = $menu->items()->create(['url' => '/diensten', 'label' => 'Diensten', 'sort_order' => 1]);
    $item = $menu->items()->create(['url' => '/oud', 'label' => 'Oud', 'sort_order' => 2]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, ['menu' => 'main', 'item_id' => $item->id, 'url' => '/nieuw', 'label' => 'Nieuw', 'parent_id' => $parent->id])
        ->assertOk();

    expect($item->refresh()->only(['url', 'label', 'parent_id', 'sort_order']))->toBe([
        'url' => '/nieuw',
        'label' => 'Nieuw',
        'parent_id' => $parent->id,
        'sort_order' => 2,
    ]);
});

it('returns menu item validation failures as an error', function (array $arguments, string $message): void {
    $menu = Menu::forKey('main');
    $parent = $menu->items()->create(['url' => '/a', 'label' => 'A', 'sort_order' => 1]);
    $menu->items()->create(['url' => '/b', 'label' => 'B', 'parent_id' => $parent->id, 'sort_order' => 2]);
    $other = $menu->items()->create(['url' => '/c', 'label' => 'C', 'sort_order' => 3]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, [
            'menu' => 'main',
            ...array_map(fn (mixed $value): mixed => match ($value) {
                'PARENT' => $parent->id,
                'OTHER' => $other->id,
                default => $value,
            }, $arguments),
        ])
        ->assertHasErrors([$message]);

    expect(MenuItem::query()
        ->count())->toBe(3)
        ->and($parent->refresh()->parent_id)->toBeNull();
})->with([
    'unsafe url' => [['url' => 'javascript:alert(1)', 'label' => 'X'], 'Use a path such as /contact'],
    'url without label' => [['url' => '/x'], 'label'],
    'nothing to link' => [['label' => 'X'], 'url'],
    'unknown page' => [['page_id' => 999], 'page id'],
    'parent from another level' => [['url' => '/c', 'label' => 'C', 'parent_id' => 999], 'top-level item'],
    'item under itself' => [['item_id' => 'PARENT', 'url' => '/a', 'label' => 'A', 'parent_id' => 'PARENT'], 'top-level item'],
    'item with children under another item' => [['item_id' => 'PARENT', 'url' => '/a', 'label' => 'A', 'parent_id' => 'OTHER'], 'has items below it'],
]);

it('creates a redirect that takes effect immediately', function (): void {
    makePage(['title' => 'Nieuw', 'slug' => 'nieuw']);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectSaveTool::class, ['from_path' => '/Oude-Pagina/', 'to' => '/nieuw'])
        ->assertOk();

    $redirect = Redirect::query()
        ->sole();

    expect($redirect->from_path)->toBe('oude-pagina')
        ->and($redirect->to)->toBe('/nieuw')
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->source)->toBe(RedirectSource::Manual);

    $this->get('/oude-pagina')->assertRedirect('/nieuw')->assertStatus(301);
});

it('updates an existing redirect', function (): void {
    $redirect = makeRedirect('oud', '/a');

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectSaveTool::class, ['redirect_id' => $redirect->id, 'from_path' => '/oud', 'to' => 'https://example.org/b', 'status_code' => 302])
        ->assertOk();

    expect($redirect->refresh()->only(['to', 'status_code']))->toBe([
        'to' => 'https://example.org/b',
        'status_code' => 302,
    ]);
});

it('returns redirect validation failures as an error', function (array $arguments, string $message): void {
    makePage(['title' => 'Bestaat', 'slug' => 'bestaat']);
    makeRedirect('a', '/b');

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectSaveTool::class, $arguments)
        ->assertHasErrors([$message]);

    expect(Redirect::query()
        ->count())->toBe(1);
})->with([
    'bad target' => [['from_path' => '/x', 'to' => 'ftp://example.org'], 'to'],
    'script target' => [['from_path' => '/x', 'to' => 'javascript:alert(1)'], 'to'],
    'from a page' => [['from_path' => '/bestaat', 'to' => '/elders'], 'belongs to a page'],
    'loop' => [['from_path' => '/b', 'to' => '/a'], 'loop'],
    'taken' => [['from_path' => '/a', 'to' => '/c'], 'already exists'],
    'bad status code' => [['from_path' => '/x', 'to' => '/y', 'status_code' => 307], 'status code'],
]);

it('deletes a redirect', function (): void {
    $redirect = makeRedirect('oud', '/nieuw');

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectDeleteTool::class, ['redirect_id' => $redirect->id])
        ->assertOk();

    expect(Redirect::query()
        ->count())->toBe(0);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectDeleteTool::class, ['redirect_id' => $redirect->id])
        ->assertHasErrors(['Use redirects_list']);
});

it('lists redirects filtered on the from path and paginated', function (): void {
    foreach (['blog/een', 'blog/twee', 'blog/drie', 'contact-oud'] as $from) {
        makeRedirect($from, '/nieuw');
    }

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectsListTool::class, ['from' => '/blog', 'per_page' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('total', 3)
            ->where('has_more', true)
            ->has('redirects', 2)
            ->where('redirects.0.from_path', '/blog/drie')
            ->where('redirects.1.from_path', '/blog/een')
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectsListTool::class, ['from' => 'blog', 'per_page' => 2, 'page' => 2])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('has_more', false)
            ->where('redirects.0.from_path', '/blog/twee')
            ->etc());
});

it('lists media with a url and whether each image is in use', function (): void {
    $used = uploadImage(alt: 'Gebruikt');
    $unused = uploadImage(alt: 'Ongebruikt');
    addBlock(makePage(['title' => 'Foto']), 'image', ['media_id' => $used->id]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaListTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('total', 2)
            ->where('media.0.id', $unused->id)
            ->where('media.0.in_use', false)
            ->where('media.1.id', $used->id)
            ->where('media.1.alt', 'Gebruikt')
            ->where('media.1.in_use', true)
            ->where('media.1.url', fn (mixed $url): bool => is_string($url) && $url !== '')
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::Media))
        ->tool(MediaListTool::class, ['search' => 'Ongebr'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('total', 1)
            ->where('media.0.id', $unused->id)
            ->etc());
});

it('keeps omitted fields when it updates a menu item', function (): void {
    $menu = Menu::forKey('main');
    $parent = $menu->items()->create(['url' => '/diensten', 'label' => 'Diensten', 'sort_order' => 1]);
    $item = $menu->items()->create(['url' => '/oud', 'label' => 'Oud', 'parent_id' => $parent->id, 'sort_order' => 2]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, ['menu' => 'main', 'item_id' => $item->id, 'url' => '/nieuw'])
        ->assertOk();

    expect($item->refresh()->only(['url', 'label', 'parent_id']))->toBe([
        'url' => '/nieuw',
        'label' => 'Oud',
        'parent_id' => $parent->id,
    ]);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemSaveTool::class, ['menu' => 'main', 'item_id' => $item->id, 'parent_id' => null])
        ->assertOk();

    expect($item->refresh()->only(['url', 'label', 'parent_id']))->toBe([
        'url' => '/nieuw',
        'label' => 'Oud',
        'parent_id' => null,
    ]);
});

it('does not create a menu when it lists one that has no items yet', function (): void {
    CmsTestServer::actingAs(cmsUser(CmsPermission::Menus))
        ->tool(MenuItemsListTool::class, ['menu' => 'footer'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('menus.0.key', 'footer')
            ->where('menus.0.items', [])
            ->etc());

    expect(Menu::query()
        ->count())->toBe(0);
});

it('keeps the status code of a redirect when an update leaves it out', function (): void {
    $redirect = makeRedirect('tijdelijk', '/a', 302);

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectSaveTool::class, ['redirect_id' => $redirect->id, 'from_path' => '/tijdelijk', 'to' => '/b'])
        ->assertOk();

    expect($redirect->refresh()->only(['to', 'status_code']))->toBe([
        'to' => '/b',
        'status_code' => 302,
    ]);
});

it('treats LIKE wildcards in a search as plain text', function (): void {
    makeRedirect('blog_een', '/a');
    makeRedirect('blogxeen', '/b');
    makeRedirect('honderd-procent', '/c');

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectsListTool::class, ['from' => 'blog_'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('total', 1)
            ->where('redirects.0.from_path', '/blog_een')
            ->etc());

    CmsTestServer::actingAs(cmsUser(CmsPermission::Redirects))
        ->tool(RedirectsListTool::class, ['from' => '%'])
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('total', 0)
            ->etc());
});
