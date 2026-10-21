<?php

declare(strict_types=1);

use J87NL\Cms\Actions\ReorderBlocks;
use J87NL\Cms\Actions\RestoreRevision;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Cache\PageCache;
use J87NL\Cms\Models\Revision;

test('restoring a revision restores the page fields and blocks', function (): void {
    $page = makePage([
        'title' => 'Original',
        'slug' => 'original',
        'meta_description' => 'Original description',
    ]);
    addBlock($page, 'rich_text', [
        'html' => '<p>Original text</p>',
    ]);

    $revision = Revision::query()
        ->where('page_id', $page->id)
        ->latest('id')
        ->firstOrFail();

    app(SavePage::class)->handle($page, [
        'title' => 'Changed',
        'slug' => 'changed',
        'meta_description' => 'Changed description',
    ], null);
    $page->blocks()->first()->delete();
    addBlock($page, 'call_to_action', [
        'title' => 'New',
        'button_label' => 'Go',
        'url' => '/go',
    ]);

    app(RestoreRevision::class)->handle($revision, null);

    $page->refresh();

    expect($page->title)->toBe('Original')
        ->and($page->path)->toBe('original')
        ->and($page->meta_description)->toBe('Original description')
        ->and($page->blocks()->get()->map->only(['type', 'data'])->all())->toBe([
            [
                'type' => 'rich_text',
                'data' => [
                    'html' => '<p>Original text</p>',
                ],
            ],
        ]);
});

test('only the latest twenty revisions are kept per page', function (): void {
    $page = makePage([
        'title' => 'Version 0',
    ]);
    $other = makePage([
        'title' => 'Other',
    ]);

    foreach (range(1, 25) as $version) {
        app(SavePage::class)->handle($page, [
            'title' => sprintf('Version %d', $version),
        ], null);
    }

    $kept = Revision::query()
        ->where('page_id', $page->id)
        ->orderBy('id')
        ->get();

    expect($kept)->toHaveCount(20)
        ->and($kept->last()->snapshot['page']['title'])->toBe('Version 25')
        ->and($kept->first()->snapshot['page']['title'])->toBe('Version 6')
        ->and(Revision::query()
            ->where('page_id', $other->id)->count())->toBe(1);
});

test('a reorder that keeps the order records no revision and leaves the cache alone', function (): void {
    $page = makePage(['title' => 'Order']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>One</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Two</p>']);

    $revisions = Revision::query()
        ->where('page_id', $page->id)
        ->count();
    $version = app(PageCache::class)->version();

    app(ReorderBlocks::class)->handle($page, [$first->id, $second->id], null);
    app(ReorderBlocks::class)->move($page, $first->id, $first->id, null);

    expect(Revision::query()
        ->where('page_id', $page->id)
        ->count())->toBe($revisions)
        ->and(app(PageCache::class)->version())->toBe($version);

    app(ReorderBlocks::class)->handle($page, [$second->id, $first->id], null);

    expect(Revision::query()
        ->where('page_id', $page->id)
        ->count())->toBe($revisions + 1)
        ->and(pageBlockIds($page))->toBe([$second->id, $first->id]);
});
