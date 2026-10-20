<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Http\Middleware\CachePublicResponses;
use J87NL\Cms\Models\Menu;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->page = makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);

    $this->block = addBlock($this->page, 'rich_text', [
        'html' => '<p>First version</p>',
    ]);
});

test('a second anonymous request is served from the cache', function (): void {
    $this->get('/about')->assertOk()->assertHeader('X-Cms-Cache', 'miss');

    $response = $this->get('/about')
        ->assertOk()
        ->assertHeader('X-Cms-Cache', 'hit')
        ->assertSee('First version', false);

    expect($response->headers->getCookies())->toBeEmpty();
});

test('editing content, menus and settings invalidates the cache', function (Closure $change): void {
    $this->get('/about')->assertHeader('X-Cms-Cache', 'miss');
    $this->get('/about')->assertHeader('X-Cms-Cache', 'hit');

    $change($this);

    $this->get('/about')->assertHeader('X-Cms-Cache', 'miss');
})->with([
    'block' => [fn ($test) => addBlock($test->page, 'rich_text', ['html' => '<p>Second</p>'])],
    'menu' => [fn ($test) => Menu::forKey('main')->items()->create(['page_id' => $test->page->id])],
    'settings' => [fn () => app(SaveSiteSettings::class)->handle(['site_name' => 'Other'])],
]);

test('unpublishing invalidates the cache', function (): void {
    $this->get('/about')->assertOk();

    app(ChangePageStatus::class)->handle($this->page, PageStatus::Unpublished, null);

    $this->get('/about')->assertNotFound();
});

test('requests with a session cookie or a query string bypass the cache', function (): void {
    $this->get('/about');

    $this->withUnencryptedCookie((string) config('session.cookie'), 'abc')
        ->get('/about')
        ->assertHeader('X-Cms-Cache', 'bypass');

    $this->get('/about?ref=newsletter')->assertHeader('X-Cms-Cache', 'bypass');
});

test('not found responses are not cached', function (): void {
    $this->get('/missing')->assertNotFound()->assertHeaderMissing('X-Cms-Cache');
    $this->get('/missing')->assertNotFound()->assertHeaderMissing('X-Cms-Cache');
});

test('the cache can be switched off', function (): void {
    config()->set('cms.cache.enabled', false);

    $this->get('/about')->assertOk()->assertHeaderMissing('X-Cms-Cache');
    $this->get('/about')->assertOk()->assertHeaderMissing('X-Cms-Cache');
});

test('a head request never stores an empty body for the following get', function (): void {
    $this->call('HEAD', '/about')->assertOk();

    $this->get('/about')
        ->assertOk()
        ->assertHeader('X-Cms-Cache', 'miss')
        ->assertSee('First version', false);

    $this->get('/about')
        ->assertHeader('X-Cms-Cache', 'hit')
        ->assertSee('First version', false);

    $this->call('HEAD', '/about')->assertOk()->assertHeader('X-Cms-Cache', 'hit');
});

test('a response rendered while the cache is flushed is not served after the flush', function (): void {
    app(CachePublicResponses::class)->handle(Request::create('/about'), function (): Response {
        app(PageCache::class)->flush();

        return new Response('Stale version');
    });

    $this->get('/about')
        ->assertHeader('X-Cms-Cache', 'miss')
        ->assertSee('First version', false)
        ->assertDontSee('Stale version');
});

test('the flush command drops cached pages so a changed view is served', function (): void {
    $this->get('/about')->assertHeader('X-Cms-Cache', 'miss');
    $this->get('/about')->assertHeader('X-Cms-Cache', 'hit');

    $this->artisan('cms:flush-page-cache')->assertSuccessful();

    $this->get('/about')->assertHeader('X-Cms-Cache', 'miss');
});
