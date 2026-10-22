<?php

declare(strict_types=1);

use J87NL\Cms\Actions\DiffPendingRevision;
use J87NL\Cms\Actions\PublishPage;
use J87NL\Cms\Actions\SaveBlock;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Html\PlainText;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;
use J87NL\Cms\Pages\WordDiff;

function pendingEditor(): PendingRevisionEditor
{
    return app(PendingRevisionEditor::class);
}

/**
 * @return array<string, mixed>|null
 */
function pendingDiff(Page $page): ?array
{
    return app(DiffPendingRevision::class)->handle($page);
}

/**
 * @param  array<string, mixed>  $diff
 * @return list<array{int, string, bool}>
 */
function diffBlockStatuses(array $diff): array
{
    return array_map(fn (array $block): array => [
        $block['id'],
        $block['status'],
        $block['moved'],
    ], $diff['blocks']);
}

test('a page without a pending revision has no diff', function (): void {
    $page = makePage(['title' => 'Live']);

    expect(pendingDiff($page))->toBeNull();
});

test('the diff lists the changed page fields with their live and pending value', function (): void {
    $page = makePage(['title' => 'Live', 'meta_description' => 'Oud']);

    pendingEditor()->updatePage($page, [
        'title' => 'Nieuw',
        'meta_description' => 'Oud',
        'noindex' => true,
    ], null);

    $diff = pendingDiff($page);

    expect($diff['fields'])->toBe([
        [
            'field' => 'title',
            'before' => 'Live',
            'after' => 'Nieuw',
        ],
        [
            'field' => 'noindex',
            'before' => false,
            'after' => true,
        ],
    ])
        ->and($diff['summary'])->toBe([
            'fields' => 2,
            'added' => 0,
            'removed' => 0,
            'changed' => 0,
            'moved' => 0,
            'reordered' => false,
        ])
        ->and($diff['revision_id'])->toBe($page->pendingRevision->id);
});

test('the diff shows an added block at its new position with its content', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

    pendingEditor()->addBlock($page, app(BlockRegistry::class)->find('call_to_action'), [
        'title' => 'Kom langs',
        'button_label' => 'Contact',
        'url' => '/contact',
    ], null);
    pendingEditor()->reorderBlocks($page, [$first->id, -1, $second->id], null);

    $diff = pendingDiff($page);
    $added = $diff['blocks'][1];

    expect(diffBlockStatuses($diff))->toBe([
        [$first->id, 'unchanged', false],
        [-1, 'added', false],
        [$second->id, 'unchanged', false],
    ])
        ->and($added['type'])->toBe('call_to_action')
        ->and($added['label'])->toBe(__('cms::blocks.call_to_action.label'))
        ->and($added['position'])->toBe(['before' => null, 'after' => 2])
        ->and($added['visible'])->toBe(['before' => null, 'after' => true])
        ->and(collect($added['changes'])->firstWhere('key', 'title'))->toBe([
            'key' => 'title',
            'before' => null,
            'after' => 'Kom langs',
            'words' => null,
            'markup_changed' => false,
            'links' => null,
        ])
        ->and($diff['summary']['added'])->toBe(1)
        ->and($diff['summary']['reordered'])->toBeFalse();
});

test('the diff keeps a removed block at the place it had on the live page', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>Weg <strong>ermee</strong></p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>Drie</p>']);

    pendingEditor()->removeBlock($page, $removed->id, null);

    $diff = pendingDiff($page);

    expect(diffBlockStatuses($diff))->toBe([
        [$first->id, 'unchanged', false],
        [$removed->id, 'removed', false],
        [$third->id, 'unchanged', false],
    ])
        ->and($diff['blocks'][1]['position'])->toBe(['before' => 2, 'after' => null])
        ->and($diff['blocks'][1]['changes'])->toBe([
            [
                'key' => 'html',
                'before' => 'Weg ermee',
                'after' => null,
                'words' => null,
                'markup_changed' => false,
                'links' => null,
            ],
        ])
        ->and($diff['summary']['removed'])->toBe(1);
});

test('the diff puts a removed first block at the top', function (): void {
    $page = makePage(['title' => 'Live']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $kept = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

    pendingEditor()->removeBlock($page, $removed->id, null);

    expect(diffBlockStatuses(pendingDiff($page)))->toBe([
        [$removed->id, 'removed', false],
        [$kept->id, 'unchanged', false],
    ]);
});

test('the diff shows a word level diff of the plain text of changed rich text', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'rich_text', ['html' => '<p>De markt is op <strong>zaterdag</strong> open.</p>']);

    pendingEditor()->updateBlock($page, $block->id, app(BlockRegistry::class)->find('rich_text'), [
        'html' => '<p>De markt is op <em>zondag</em> open vanaf negen uur.</p>',
    ], null);

    $diff = pendingDiff($page);

    expect(diffBlockStatuses($diff))->toBe([
        [$block->id, 'changed', false],
    ])
        ->and($diff['blocks'][0]['changes'])->toBe([
            [
                'key' => 'html',
                'before' => 'De markt is op zaterdag open.',
                'after' => 'De markt is op zondag open vanaf negen uur.',
                'words' => [
                    ['type' => 'equal', 'text' => 'De markt is op '],
                    ['type' => 'removed', 'text' => 'zaterdag'],
                    ['type' => 'added', 'text' => 'zondag'],
                    ['type' => 'equal', 'text' => ' '],
                    ['type' => 'removed', 'text' => 'open.'],
                    ['type' => 'added', 'text' => 'open vanaf negen uur.'],
                ],
                'markup_changed' => false,
                'links' => null,
            ],
        ])
        ->and($diff['summary']['changed'])->toBe(1);
});

test('the diff names the changed values inside nested block data', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'link_tiles', [
        'title' => 'Sponsors',
        'tiles' => [
            ['name' => 'Bakker', 'url' => 'https://bakker.example'],
            ['name' => 'Slager', 'url' => 'https://slager.example'],
        ],
    ]);

    pendingEditor()->updateBlock($page, $block->id, app(BlockRegistry::class)->find('link_tiles'), [
        'title' => 'Sponsors',
        'tiles' => [
            ['name' => 'Bakker', 'url' => 'https://bakker.example'],
            ['name' => 'Groenteboer', 'url' => 'https://slager.example'],
        ],
    ], null);

    expect(pendingDiff($page)['blocks'][0]['changes'])->toBe([
        [
            'key' => 'tiles.1.name',
            'before' => 'Slager',
            'after' => 'Groenteboer',
            'words' => [
                ['type' => 'removed', 'text' => 'Slager'],
                ['type' => 'added', 'text' => 'Groenteboer'],
            ],
            'markup_changed' => false,
            'links' => null,
        ],
    ]);
});

test('the diff marks only the blocks that moved', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>2</p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>3</p>']);

    pendingEditor()->reorderBlocks($page, [$third->id, $first->id, $second->id], null);

    $diff = pendingDiff($page);

    expect(diffBlockStatuses($diff))->toBe([
        [$third->id, 'unchanged', true],
        [$first->id, 'unchanged', false],
        [$second->id, 'unchanged', false],
    ])
        ->and($diff['blocks'][0]['position'])->toBe(['before' => 3, 'after' => 1])
        ->and($diff['summary']['moved'])->toBe(1)
        ->and($diff['summary']['reordered'])->toBeTrue();
});

test('the diff flags a rich text change that only touches the markup and lists changed link targets', function (): void {
    $page = makePage(['title' => 'Live']);
    $link = addBlock($page, 'rich_text', ['html' => '<p>Lees <a href="https://example.com/info">meer</a></p>']);
    $bold = addBlock($page, 'rich_text', ['html' => '<p>Open op zaterdag</p>']);
    $type = app(BlockRegistry::class)->find('rich_text');

    pendingEditor()->updateBlock($page, $link->id, $type, ['html' => '<p>Lees <a href="https://evil.example/info">meer</a></p>'], null);
    pendingEditor()->updateBlock($page, $bold->id, $type, ['html' => '<p>Open op <strong>zaterdag</strong></p>'], null);

    $diff = pendingDiff($page);

    expect(diffBlockStatuses($diff))->toBe([
        [$link->id, 'changed', false],
        [$bold->id, 'changed', false],
    ])
        ->and($diff['blocks'][0]['changes'])->toBe([
            [
                'key' => 'html',
                'before' => 'Lees meer',
                'after' => 'Lees meer',
                'words' => [
                    ['type' => 'equal', 'text' => 'Lees meer'],
                ],
                'markup_changed' => true,
                'links' => [
                    'before' => ['https://example.com/info'],
                    'after' => ['https://evil.example/info'],
                ],
            ],
        ])
        ->and($diff['blocks'][1]['changes'][0]['markup_changed'])->toBeTrue()
        ->and($diff['blocks'][1]['changes'][0]['links'])->toBeNull();
});

test('the diff shows a block that changed and moved', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>Drie</p>']);

    pendingEditor()->updateBlock($page, $third->id, app(BlockRegistry::class)->find('rich_text'), ['html' => '<p>Drie nieuw</p>'], null);
    pendingEditor()->reorderBlocks($page, [$third->id, $first->id, $second->id], null);

    $diff = pendingDiff($page);

    expect(diffBlockStatuses($diff))->toBe([
        [$third->id, 'changed', true],
        [$first->id, 'unchanged', false],
        [$second->id, 'unchanged', false],
    ])
        ->and($diff['blocks'][0]['position'])->toBe(['before' => 3, 'after' => 1])
        ->and(array_column($diff['blocks'][0]['changes'], 'after'))->toBe(['Drie nieuw'])
        ->and($diff['summary']['changed'])->toBe(1)
        ->and($diff['summary']['moved'])->toBe(1);
});

test('the diff keeps a removed block next to its stable neighbour when the block before it moved', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>C</p>']);
    $fourth = addBlock($page, 'rich_text', ['html' => '<p>D</p>']);

    pendingEditor()->removeBlock($page, $removed->id, null);
    pendingEditor()->reorderBlocks($page, [$third->id, $fourth->id, $first->id], null);

    expect(diffBlockStatuses(pendingDiff($page)))->toBe([
        [$removed->id, 'removed', false],
        [$third->id, 'unchanged', false],
        [$fourth->id, 'unchanged', false],
        [$first->id, 'unchanged', true],
    ]);
});

test('the diff keeps a removed last block at the bottom', function (): void {
    $page = makePage(['title' => 'Live']);
    $kept = addBlock($page, 'rich_text', ['html' => '<p>Een</p>']);
    $removed = addBlock($page, 'rich_text', ['html' => '<p>Twee</p>']);

    pendingEditor()->removeBlock($page, $removed->id, null);

    expect(diffBlockStatuses(pendingDiff($page)))->toBe([
        [$kept->id, 'unchanged', false],
        [$removed->id, 'removed', false],
    ]);
});

test('the diff keeps consecutive removed blocks together in their live order', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $removedFirst = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);
    $removedSecond = addBlock($page, 'rich_text', ['html' => '<p>C</p>']);
    $last = addBlock($page, 'rich_text', ['html' => '<p>D</p>']);

    pendingEditor()->removeBlock($page, $removedFirst->id, null);
    pendingEditor()->removeBlock($page, $removedSecond->id, null);

    expect(diffBlockStatuses(pendingDiff($page)))->toBe([
        [$first->id, 'unchanged', false],
        [$removedFirst->id, 'removed', false],
        [$removedSecond->id, 'removed', false],
        [$last->id, 'unchanged', false],
    ]);
});

test('the diff lists every block as removed in its live order when all blocks go', function (): void {
    $page = makePage(['title' => 'Live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);

    pendingEditor()->removeBlock($page, $first->id, null);
    pendingEditor()->removeBlock($page, $second->id, null);
    pendingEditor()->addBlock($page, app(BlockRegistry::class)->find('rich_text'), ['html' => '<p>Nieuw</p>'], null);

    expect(diffBlockStatuses(pendingDiff($page)))->toBe([
        [$first->id, 'removed', false],
        [$second->id, 'removed', false],
        [-1, 'added', false],
    ]);
});

test('the link targets of HTML are read from the attributes, not from look-alike text', function (): void {
    expect(PlainText::links(implode('', [
        '<p title=" href=&quot;https://decoy.example&quot;">Lees ',
        '<a title=\'x href=https://decoy.example\' href="https://example.com/?a=1,2&amp;b=3">meer</a>',
        '<img src="/a.png" srcset="/a.png 1x, /b.png 2x" alt="">',
        '<a href=/kaal>kaal</a></p>',
    ])))->toBe([
        'https://example.com/?a=1,2&b=3',
        '/a.png',
        '/a.png 1x, /b.png 2x',
        '/kaal',
    ]);
});

test('the diff can leave out the word level diff', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'rich_text', ['html' => '<p>Oud</p>']);

    pendingEditor()->updateBlock($page, $block->id, app(BlockRegistry::class)->find('rich_text'), ['html' => '<p>Nieuw</p>'], null);

    $diff = app(DiffPendingRevision::class)->handle($page, $page->pendingRevision()->firstOrFail(), withWords: false);

    expect($diff['blocks'][0]['changes'][0]['before'])->toBe('Oud')
        ->and($diff['blocks'][0]['changes'][0]['after'])->toBe('Nieuw')
        ->and($diff['blocks'][0]['changes'][0]['words'])->toBeNull();
});

test('the diff shows a change of visibility', function (): void {
    $page = makePage(['title' => 'Live']);
    $block = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);
    $editor = pendingEditor();

    $editor->updatePage($page, ['title' => 'Nieuw'], null);

    $revision = $page->pendingRevision()->firstOrFail();
    $snapshot = $revision->snapshot;
    $snapshot['blocks'][0]['is_visible'] = false;
    $revision->snapshot = $snapshot;
    $revision->save();

    $entry = pendingDiff($page)['blocks'][0];

    expect($entry['id'])->toBe($block->id)
        ->and($entry['status'])->toBe('changed')
        ->and($entry['visible'])->toBe(['before' => true, 'after' => false])
        ->and($entry['changes'])->toBe([]);
});

test('the diff compares with the live page, so live changes the pending revision left alone do not show', function (): void {
    $page = makePage(['title' => 'Live', 'meta_title' => 'Oud']);
    $block = addBlock($page, 'rich_text', ['html' => '<p>1</p>']);

    pendingEditor()->updatePage($page, ['title' => 'Voorstel'], null);

    $page->meta_title = 'Live later';
    $page->save();

    app(SaveBlock::class)->handle($page, $block, app(BlockRegistry::class)->find('rich_text'), ['html' => '<p>live later</p>'], null);

    $diff = pendingDiff($page->refresh());

    expect(array_column($diff['fields'], 'field'))->toBe(['title'])
        ->and(diffBlockStatuses($diff))->toBe([
            [$block->id, 'unchanged', false],
        ]);
});

test('the diff and the preview end where publishing ends', function (): void {
    makePage(['title' => 'Home', 'slug' => 'home'], home: true);
    $page = makePage(['title' => 'Live', 'slug' => 'live']);
    $first = addBlock($page, 'rich_text', ['html' => '<p>A</p>']);
    $second = addBlock($page, 'rich_text', ['html' => '<p>B</p>']);
    $third = addBlock($page, 'rich_text', ['html' => '<p>C</p>']);
    $type = app(BlockRegistry::class)->find('rich_text');
    $editor = pendingEditor();

    $editor->addBlock($page, $type, ['html' => '<p>P</p>'], null);
    $editor->removeBlock($page, $second->id, null);
    $editor->updateBlock($page, $third->id, $type, ['html' => '<p>C nieuw</p>'], null);
    $editor->reorderBlocks($page, [$third->id, -1, $first->id], null);

    $live = addBlock($page, 'rich_text', ['html' => '<p>live</p>']);
    $known = [$first->id, $third->id, $live->id];

    $diffIds = array_values(array_map(
        fn (array $block): string => in_array($block['id'], $known, true) ? (string) $block['id'] : 'new',
        array_filter(pendingDiff($page)['blocks'], fn (array $block): bool => $block['status'] !== 'removed'),
    ));
    $previewHtml = app(PendingRevisionApplier::class)
        ->plan($page, $page->pendingRevision()->firstOrFail())
        ->page($page)
        ->blocks
        ->map(fn (Block $block): mixed => $block->data['html'])
        ->all();

    app(PublishPage::class)->handle($page, null);

    $publishedIds = array_map(fn (int $id): string => in_array($id, $known, true) ? (string) $id : 'new', pageBlockIds($page));

    expect($diffIds)->toBe($publishedIds)
        ->and($previewHtml)->toBe(['<p>C nieuw</p>', '<p>P</p>', '<p>A</p>', '<p>live</p>'])
        ->and($page->blocks()->get()->map(fn (Block $block): mixed => $block->data['html'])->all())->toBe($previewHtml);
});

test('a word diff keeps unchanged words and marks added and removed ones', function (string $before, string $after, array $expected): void {
    expect(WordDiff::words($before, $after))->toBe($expected);
})->with([
    'equal' => ['een twee', 'een  twee', [['type' => 'equal', 'text' => 'een twee']]],
    'added at the end' => ['een', 'een twee', [['type' => 'equal', 'text' => 'een'], ['type' => 'added', 'text' => ' twee']]],
    'removed at the start' => ['een twee', 'twee', [['type' => 'removed', 'text' => 'een '], ['type' => 'equal', 'text' => 'twee']]],
    'from nothing' => ['', 'nieuw', [['type' => 'added', 'text' => 'nieuw']]],
    'to nothing' => ['oud', '', [['type' => 'removed', 'text' => 'oud']]],
    'replaced in the middle' => ['a b c', 'a x c', [['type' => 'equal', 'text' => 'a '], ['type' => 'removed', 'text' => 'b'], ['type' => 'added', 'text' => 'x'], ['type' => 'equal', 'text' => ' c']]],
]);

test('a word diff of texts that differ everywhere falls back to removing and adding the middle', function (): void {
    $before = implode(' ', array_map(fn (int $index): string => sprintf('oud%d', $index), range(1, 500)));
    $after = implode(' ', array_map(fn (int $index): string => sprintf('nieuw%d', $index), range(1, 500)));

    $segments = WordDiff::words(sprintf('begin %s eind', $before), sprintf('begin %s eind', $after));

    expect($segments)->toBe([
        ['type' => 'equal', 'text' => 'begin '],
        ['type' => 'removed', 'text' => $before],
        ['type' => 'added', 'text' => $after],
        ['type' => 'equal', 'text' => ' eind'],
    ]);
});

test('a word diff finds a few changes in a long text', function (): void {
    $words = array_map(fn (int $index): string => sprintf('woord%d', $index), range(1, 3000));
    $changed = $words;
    $changed[10] = 'anders';
    $changed[2900] = 'ook';

    $segments = WordDiff::words(implode(' ', $words), implode(' ', $changed));

    expect(array_values(array_filter($segments, fn (array $segment): bool => $segment['type'] !== 'equal')))->toBe([
        ['type' => 'removed', 'text' => 'woord11'],
        ['type' => 'added', 'text' => 'anders'],
        ['type' => 'removed', 'text' => 'woord2901'],
        ['type' => 'added', 'text' => 'ook'],
    ]);
});
