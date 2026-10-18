<?php

declare(strict_types=1);

use J87NL\Cms\Actions\SavePage;
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
