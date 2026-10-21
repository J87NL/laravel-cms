<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\ReorderBlocks;
use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Http\Middleware\CachePublicResponses;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Menu;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
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

test('media of other models leaves the cache alone, CMS media flushes it', function (): void {
    Storage::fake('public');

    $cache = app(PageCache::class);
    $version = $cache->version();

    $media = new Media([
        'model_type' => 'App\\Models\\Product',
        'model_id' => 1,
        'uuid' => (string) Str::uuid(),
        'collection_name' => 'photos',
        'name' => 'product',
        'file_name' => 'product.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 1,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->save();
    $media->delete();

    expect($cache->version())->toBe($version);

    uploadImage();

    expect($cache->version())->toBeGreaterThan($version);
});

test('a transaction that changes many blocks flushes the cache once', function (): void {
    $second = addBlock($this->page, 'rich_text', ['html' => '<p>Second</p>']);
    $third = addBlock($this->page, 'rich_text', ['html' => '<p>Third</p>']);

    $cache = app(PageCache::class);
    $version = $cache->version();

    app(ReorderBlocks::class)->handle($this->page, [$third->id, $second->id, $this->block->id], null);

    expect($cache->version())->toBe($version + 1);
});

test('a rolled back transaction leaves the cache alone and later changes still flush it', function (): void {
    $cache = app(PageCache::class);
    $version = $cache->version();

    try {
        DB::transaction(function (): void {
            addBlock($this->page, 'rich_text', ['html' => '<p>Never</p>']);

            throw new RuntimeException('Rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($cache->version())->toBe($version);

    addBlock($this->page, 'rich_text', ['html' => '<p>Kept</p>']);

    expect($cache->version())->toBe($version + 1);
});

test('a later save still flushes after an earlier commit callback threw', function (): void {
    $cache = app(PageCache::class);
    $version = $cache->version();

    try {
        DB::transaction(function (): void {
            DB::afterCommit(fn () => throw new RuntimeException('Other listener'));

            $this->block->is_visible = false;
            $this->block->save();
        });
    } catch (RuntimeException) {
    }

    addBlock($this->page, 'rich_text', ['html' => '<p>Second</p>']);

    expect($cache->version())->toBeGreaterThan($version);
});

test('a rolled back savepoint keeps the flush of its outer transaction', function (bool $outerSavesFirst): void {
    $cache = app(PageCache::class);
    $version = $cache->version();

    DB::transaction(function () use ($outerSavesFirst): void {
        if ($outerSavesFirst) {
            addBlock($this->page, 'rich_text', ['html' => '<p>Outer</p>']);
        }

        try {
            DB::transaction(function (): void {
                addBlock($this->page, 'rich_text', ['html' => '<p>Inner</p>']);

                throw new RuntimeException('Inner rolled back');
            });
        } catch (RuntimeException) {
        }

        if (! $outerSavesFirst) {
            addBlock($this->page, 'rich_text', ['html' => '<p>Outer</p>']);
        }
    });

    expect($cache->version())->toBe($version + 1);
})->with([
    'outer saves first' => [true],
    'outer saves after the savepoint' => [false],
]);

test('CMS media under a morph map alias still flushes the cache', function (): void {
    Storage::fake('public');
    Relation::morphMap([
        'cms-media-asset' => MediaAsset::class,
    ]);

    $asset = uploadImage();
    $cache = app(PageCache::class);
    $version = $cache->version();

    $media = $asset->file();
    $media->name = 'renamed';
    $media->save();

    expect($media->model_type)->toBe('cms-media-asset')
        ->and($cache->version())->toBe($version + 1);
});
