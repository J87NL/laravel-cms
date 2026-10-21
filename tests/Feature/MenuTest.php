<?php

declare(strict_types=1);

use J87NL\Cms\Actions\DeleteMenuItem;
use J87NL\Cms\Actions\ReorderMenuItems;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;

test('menu items that point at a page follow its path and hide unpublished pages', function (): void {
    $home = makePage([
        'title' => 'Home',
        'slug' => 'home',
    ], home: true);
    $about = makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);
    $draft = makePage([
        'title' => 'Draft',
        'slug' => 'draft',
    ], PageStatus::Draft);

    $menu = Menu::forKey('main');

    foreach ([$home, $about, $draft] as $order => $page) {
        $menu->items()->create([
            'page_id' => $page->id,
            'sort_order' => $order,
        ]);
    }

    $menu->items()->create([
        'url' => 'https://github.com/J87NL',
        'label' => 'GitHub',
        'sort_order' => 9,
    ]);

    app(SavePage::class)->handle($about, [
        'title' => 'About',
        'slug' => 'about-me',
    ], null);

    $this->get('/about-me')
        ->assertOk()
        ->assertSee('href="/"', false)
        ->assertSee('href="/about-me" aria-current="page">About', false)
        ->assertSee('href="https://github.com/J87NL"', false)
        ->assertDontSee('href="/draft"', false);
});

test('a deleted page takes its menu items with it', function (): void {
    $page = makePage([
        'slug' => 'first',
    ]);
    $other = makePage([
        'slug' => 'second',
    ]);

    Menu::forKey('main')->items()->create([
        'page_id' => $other->id,
    ]);

    $other->delete();

    expect(MenuItem::query()
        ->count())->toBe(0)
        ->and($page->exists)->toBeTrue();
});

test('menu items below an unpublished ancestor are hidden', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ], PageStatus::Draft);
    $child = makePage([
        'title' => 'Child',
        'slug' => 'child',
        'parent_id' => $parent->id,
    ]);
    $visible = makePage([
        'title' => 'Visible',
        'slug' => 'visible',
    ]);

    $menu = Menu::forKey('main');
    $menu->items()->create([
        'page_id' => $child->id,
    ]);
    $menu->items()->create([
        'page_id' => $visible->id,
    ]);

    $this->get('/visible')
        ->assertSee('href="/visible"', false)
        ->assertDontSee('href="/parent/child"', false);
});

test('reordering menu items moves one item and flushes the cache once', function (): void {
    $menu = Menu::forKey('main');
    $first = $menu->items()->create(['url' => '/one', 'label' => 'One', 'sort_order' => 1]);
    $second = $menu->items()->create(['url' => '/two', 'label' => 'Two', 'sort_order' => 2]);
    $third = $menu->items()->create(['url' => '/three', 'label' => 'Three', 'sort_order' => 3]);

    $version = app(PageCache::class)->version();

    app(ReorderMenuItems::class)->move($menu, $third->id, $first->id);

    expect(MenuItem::query()
        ->where('menu_id', $menu->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all())->toBe([$third->id, $first->id, $second->id])
        ->and(app(PageCache::class)->version())->toBe($version + 1);
});

test('reordering refuses an item of another menu and changes nothing', function (): void {
    $main = Menu::forKey('main');
    $footer = Menu::forKey('footer');
    $first = $main->items()->create(['url' => '/one', 'label' => 'One', 'sort_order' => 1]);
    $other = $footer->items()->create(['url' => '/other', 'label' => 'Other', 'sort_order' => 1]);

    expect(fn () => app(ReorderMenuItems::class)->move($main, $other->id, $first->id))
        ->toThrow(InvalidArgumentException::class);

    expect($first->refresh()->sort_order)->toBe(1)
        ->and($other->refresh()->sort_order)->toBe(1);
});

test('moving a menu item onto itself changes nothing', function (): void {
    $menu = Menu::forKey('main');
    $first = $menu->items()->create(['url' => '/one', 'label' => 'One', 'sort_order' => 1]);
    $version = app(PageCache::class)->version();

    app(ReorderMenuItems::class)->move($menu, $first->id, $first->id);

    expect(app(PageCache::class)->version())->toBe($version);
});

test('deleting a menu item removes its children and flushes the cache', function (): void {
    $menu = Menu::forKey('main');
    $parent = $menu->items()->create(['url' => '/parent', 'label' => 'Parent']);
    $menu->items()->create(['url' => '/child', 'label' => 'Child', 'parent_id' => $parent->id]);
    $kept = $menu->items()->create(['url' => '/kept', 'label' => 'Kept']);
    $version = app(PageCache::class)->version();

    app(DeleteMenuItem::class)->handle($menu, $parent);

    expect(MenuItem::query()
        ->pluck('id')
        ->all())->toBe([$kept->id])
        ->and(app(PageCache::class)->version())->toBe($version + 1);
});

test('deleting refuses an item of another menu', function (): void {
    $item = Menu::forKey('footer')->items()->create(['url' => '/other', 'label' => 'Other']);

    expect(fn () => app(DeleteMenuItem::class)->handle(Menu::forKey('main'), $item))
        ->toThrow(InvalidArgumentException::class);

    expect($item->exists)->toBeTrue()
        ->and(MenuItem::query()->count())->toBe(1);
});
