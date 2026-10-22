<?php

declare(strict_types=1);

use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Http\Controllers\PreviewController;
use J87NL\Cms\Pages\PendingRevisionEditor;

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

    $this->get(PreviewController::pendingUrl($page))
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

    $this->get(PreviewController::pendingUrl($page))
        ->assertOk()
        ->assertSee('Voorgestelde omschrijving', false)
        ->assertDontSee('Live omschrijving', false)
        ->assertDontSee('Zichtbaar', false);
});

test('a pending preview of a page without a pending revision shows the page as it is', function (): void {
    $page = makePage(['title' => 'Live titel']);
    addBlock($page, 'rich_text', ['html' => '<p>Live tekst</p>']);

    $this->get(PreviewController::pendingUrl($page))
        ->assertOk()
        ->assertSee('Live titel', false)
        ->assertSee('Live tekst', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('a pending preview link needs a valid signature and expires', function (): void {
    $page = pendingPage();
    $url = PreviewController::pendingUrl($page);

    $this->get(sprintf('/cms/preview/%d/pending', $page->id))->assertForbidden();
    $this->get(str_replace(sprintf('/preview/%d/', $page->id), sprintf('/preview/%d/', $page->id + 1), $url))->assertForbidden();

    $this->travel(61)->minutes();

    $this->get($url)->assertForbidden();
});
