<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Menu;

test('published pages render', function (): void {
    $page = makePage([
        'title' => 'About me',
        'slug' => 'about',
    ]);

    addBlock($page, 'rich_text', [
        'html' => '<p>Hello world</p>',
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertSee('About me')
        ->assertSee('<p>Hello world</p>', false);
});

test('draft and unpublished pages return a not found page', function (PageStatus $status): void {
    makePage([
        'title' => 'Hidden',
        'slug' => 'hidden',
    ], $status);

    $this->get('/hidden')
        ->assertNotFound()
        ->assertDontSee('Hidden');
})->with([
    PageStatus::Draft,
    PageStatus::Unpublished,
]);

test('unknown paths return the cms not found page', function (): void {
    $this->get('/does/not/exist')
        ->assertNotFound()
        ->assertSee(__('cms::public.not_found.title'));
});

test('public responses set no cookies and contain no scripts', function (): void {
    $page = makePage([
        'slug' => 'about',
    ]);

    addBlock($page, 'rich_text', [
        'html' => '<p>Text</p><script>alert(1)</script>',
    ]);
    addBlock($page, 'embed', [
        'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'title' => 'Video',
    ]);

    foreach (['/', '/about', '/missing'] as $path) {
        $response = $this->get($path);

        expect($response->headers->getCookies())->toBeEmpty()
            ->and(strtolower((string) $response->getContent()))->not->toContain('<script');
    }
});

test('the published home page is shown on the root', function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);

    $this->get('/')
        ->assertOk()
        ->assertSee('Welcome');

    $this->get('/welcome')->assertRedirect('/');
});

test('the fallback view is shown on the root while no home page is published', function (): void {
    config()->set('cms.fallback_home_view', 'cms-test::landing');
    view()->addNamespace('cms-test', __DIR__.'/../fixtures/views');

    makePage([
        'title' => 'Welcome',
    ], PageStatus::Draft, home: true);

    $this->get('/')
        ->assertOk()
        ->assertSee('Temporary landing page');
});

test('a path with capitals redirects to the lowercase path', function (): void {
    makePage([
        'slug' => 'about',
    ]);

    $this->get('/About')->assertRedirect('/about')->assertStatus(301);
    $this->get('/Missing')->assertNotFound();
});

test('a published page below an unpublished ancestor is not public', function (): void {
    $parent = makePage([
        'slug' => 'parent',
    ], PageStatus::Unpublished);
    makePage([
        'title' => 'Child',
        'slug' => 'child',
        'parent_id' => $parent->id,
    ]);

    $this->get('/parent/child')->assertNotFound();

    $parent->status = PageStatus::Published;
    $parent->save();

    $this->get('/parent/child')->assertOk()->assertSee('Child');
});

test('a not found page costs at most two queries', function (): void {
    Menu::forKey('main');

    DB::enableQueryLog();

    $this->get('/wp-login.php')->assertNotFound();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});

test('redirects keep the query string', function (): void {
    makePage([
        'slug' => 'about',
    ]);
    makePage([
        'slug' => 'welcome',
    ], home: true);

    $this->get('/About?ref=mail&x=1')->assertRedirect('/about?ref=mail&x=1');
    $this->get('/welcome?ref=mail')->assertRedirect('/?ref=mail');
});
