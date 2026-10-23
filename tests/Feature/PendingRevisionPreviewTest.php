<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use J87NL\Cms\Actions\DiscardPendingRevision;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Http\Controllers\PreviewController;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PendingRevisionEditor;

function pendingPreviewUrl(Page $page): string
{
    return PreviewController::pendingUrl($page, $page->pendingRevision()->firstOrFail());
}

beforeEach(function (): void {
    $this->actingAs(cmsUser(CmsPermission::PagesEdit));
});

test('a signed preview renders the page with its pending revision applied, without letting it be indexed', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live titel', 'slug' => 'over-ons']);
    $kept = addBlock($page, 'rich_text', ['html' => '<p>Blijft staan</p>']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>Gaat weg</p>']);
    $changed = addBlock($page, 'rich_text', ['html' => '<p>Oude tekst</p>']);
    $type = app(BlockRegistry::class)->find('rich_text');
    $editor = app(PendingRevisionEditor::class);

    $editor->updatePage($page, ['title' => 'Voorgestelde titel'], null);
    $editor->removeBlock($page, $removed->id, null);
    $editor->updateBlock($page, $changed->id, $type, ['html' => '<p>Nieuwe tekst</p>'], null);
    $editor->addBlock($page, $type, ['html' => '<p>Extra blok</p>'], null);
    $editor->reorderBlocks($page, [-1, $changed->id, $kept->id], null);

    $this->get(pendingPreviewUrl($page))
        ->assertOk()
        ->assertSeeInOrder([
            'Voorgestelde titel',
            'Extra blok',
            'Nieuwe tekst',
            'Blijft staan',
        ], false)
        ->assertDontSee('Gaat weg', false)
        ->assertDontSee('Oude tekst', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('X-Cms-Cache');

    $this->get('/over-ons')
        ->assertOk()
        ->assertSee('Live titel', false)
        ->assertSee('Gaat weg', false)
        ->assertSee('Oude tekst', false)
        ->assertDontSee('Extra blok', false);

    expect($page->refresh()->title)->toBe('Live titel')
        ->and(pageBlockIds($page))->toBe([$kept->id, $removed->id, $changed->id])
        ->and($page->pendingRevision()->exists())->toBeTrue();
});

test('a pending preview leaves out hidden blocks and uses the pending SEO fields', function (): void {
    $page = makePage(['title' => 'Live', 'meta_description' => 'Live omschrijving']);
    addBlock($page, 'rich_text', ['html' => '<p>Zichtbaar</p>']);
    $editor = app(PendingRevisionEditor::class);

    $editor->updatePage($page, ['meta_description' => 'Voorgestelde omschrijving'], null);

    $revision = $page->pendingRevision()->firstOrFail();
    $snapshot = $revision->snapshot;
    $snapshot['blocks'][0]['is_visible'] = false;
    $revision->snapshot = $snapshot;
    $revision->save();

    $this->get(pendingPreviewUrl($page))
        ->assertOk()
        ->assertSee('Voorgestelde omschrijving', false)
        ->assertDontSee('Live omschrijving', false)
        ->assertDontSee('Zichtbaar', false);
});

test('a pending preview runs no insert, update or delete query', function (): void {
    $page = pendingPage();
    $block = addBlock($page, 'rich_text', ['html' => '<p>Oud</p>']);
    app(PendingRevisionEditor::class)->updateBlock($page, $block->id, app(BlockRegistry::class)->find('rich_text'), ['html' => '<p>Nieuw</p>'], null);
    $url = pendingPreviewUrl($page);
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->get($url)
        ->assertOk()
        ->assertSee('Nieuw', false);

    expect($writes)->toBe([]);
});

test('a pending preview link stops working once its revision is no longer pending', function (): void {
    $page = pendingPage();
    $url = pendingPreviewUrl($page);

    app(DiscardPendingRevision::class)->handle($page);

    $this->get($url)->assertNotFound();

    app(PendingRevisionEditor::class)->updatePage($page, ['title' => 'Later voorstel'], null);

    $this->get($url)
        ->assertNotFound()
        ->assertDontSee('Later voorstel', false);
});

test('a pending preview link needs a valid signature and expires', function (): void {
    $page = pendingPage();
    $other = pendingPage('Andere');
    $revision = $page->pendingRevision()->firstOrFail();
    $url = PreviewController::pendingUrl($page, $revision);

    $this->get(sprintf('/cms/preview/%d/pending/%d', $page->id, $revision->id))->assertForbidden();
    $this->get(str_replace(sprintf('/pending/%d', $revision->id), sprintf('/pending/%d', $other->pendingRevision()->firstOrFail()->id), $url))->assertForbidden();
    $this->get(PreviewController::pendingUrl($page, $other->pendingRevision()->firstOrFail()))->assertNotFound();

    $this->travel(61)->minutes();

    $this->get($url)->assertForbidden();
});

test('a pending preview needs a signed in user with the page edit permission', function (): void {
    $page = pendingPage();
    $url = pendingPreviewUrl($page);

    $this->actingAs(cmsUser(CmsPermission::Media));
    $this->get($url)
        ->assertForbidden()
        ->assertDontSee('Live concept', false);

    auth()->logout();

    $this->getJson($url)
        ->assertUnauthorized()
        ->assertDontSee('Live concept', false);
});

test('a guest opening a pending preview is sent to the login route', function (): void {
    Route::get('login', fn (): string => 'Inloggen')->name('login');
    app('router')->getRoutes()->refreshNameLookups();

    $page = pendingPage();
    $url = pendingPreviewUrl($page);

    auth()->logout();

    $this->get($url)
        ->assertRedirect('/login')
        ->assertDontSee('Live concept', false);
});

test('the pending preview middleware can be configured', function (): void {
    $page = pendingPage();
    $url = pendingPreviewUrl($page);

    config()->set('cms.preview.pending_middleware', []);
    app('router')->setRoutes(new RouteCollection);
    require __DIR__.'/../../routes/public.php';
    app('router')->getRoutes()->refreshNameLookups();

    auth()->logout();

    $this->getJson($url)->assertForbidden();
});
