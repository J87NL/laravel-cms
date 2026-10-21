<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\DeleteMedia;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Mcp\BlockTypeSummary;
use J87NL\Cms\Mcp\CmsTools;
use J87NL\Cms\Mcp\Tools\BlockAddTool;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Tests\Fixtures\Mcp\CmsTestServer;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>  $data
 */
function linkTilesPasses(array $data): bool
{
    return Validator::make($data, app(BlockRegistry::class)->find('link_tiles')->rules())->passes();
}

function linkTilesHtml(string $html): string
{
    preg_match('#<section class="cms-block cms-block--link-tiles".*?</section>#s', $html, $matches);

    return $matches[0] ?? '';
}

test('tiles render with their image, or with their name when there is no image', function (): void {
    $logo = uploadImage(alt: 'Bakkerij logo');
    $page = makePage([
        'slug' => 'sponsors',
    ]);
    addBlock($page, 'link_tiles', [
        'title' => 'Onze sponsors',
        'rel' => 'sponsored noopener',
        'tiles' => [
            [
                'name' => 'Bakkerij Jansen',
                'media_id' => $logo->id,
                'url' => 'https://bakkerij.example',
            ],
            [
                'name' => 'Garage Pietersen',
                'media_id' => null,
                'url' => 'https://garage.example',
            ],
        ],
    ]);

    $html = linkTilesHtml((string) $this->get('/sponsors')->assertOk()->getContent());

    expect($html)
        ->toContain('<h2 class="cms-link-tiles__title" id="cms-link-tiles-0">Onze sponsors</h2>')
        ->toContain('aria-labelledby="cms-link-tiles-0"')
        ->toContain('alt="Bakkerij Jansen"')
        ->toContain('href="https://bakkerij.example"')
        ->toContain('<span class="cms-link-tiles__name">Garage Pietersen</span>')
        ->toContain('rel="sponsored noopener"')
        ->and(strpos($html, 'href="https://bakkerij.example"'))->toBeLessThan(strpos($html, 'href="https://garage.example"'));
});

test('a linked tile opens in a new tab and says so', function (): void {
    $page = makePage([
        'slug' => 'links',
    ]);
    addBlock($page, 'link_tiles', [
        'tiles' => [
            [
                'name' => 'GitHub',
                'url' => 'https://github.com/J87NL',
            ],
        ],
    ]);

    app()->setLocale('en');

    expect(linkTilesHtml((string) $this->get('/links')->getContent()))
        ->toContain('target="_blank"')
        ->toContain('aria-label="GitHub (opens in a new tab)"');
});

test('an unlinked tile with an image is named by the tile name', function (): void {
    $logo = uploadImage(alt: 'Logo');
    $page = makePage([
        'slug' => 'tiles',
    ]);
    addBlock($page, 'link_tiles', [
        'tiles' => [
            [
                'name' => 'Buurtvereniging',
                'media_id' => $logo->id,
            ],
        ],
    ]);

    expect(linkTilesHtml((string) $this->get('/tiles')->getContent()))
        ->toContain('alt="Buurtvereniging"')
        ->not->toContain('alt="Logo"');
});

test('a tile whose image was removed shows its name', function (): void {
    $page = makePage([
        'slug' => 'tiles',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'tiles' => [
                    [
                        'name' => 'Bakkerij',
                        'media_id' => 999,
                    ],
                ],
            ],
        ]);

    expect(linkTilesHtml((string) $this->get('/tiles')->assertOk()->getContent()))
        ->toContain('<span class="cms-link-tiles__name">Bakkerij</span>')
        ->not->toContain('<img');
});

test('a block without a heading is labelled for visitors', function (): void {
    $page = makePage([
        'slug' => 'tiles',
    ]);
    addBlock($page, 'link_tiles', [
        'tiles' => [
            [
                'name' => 'Bakkerij',
            ],
        ],
    ]);

    app()->setLocale('en');

    expect(linkTilesHtml((string) $this->get('/tiles')->getContent()))
        ->toContain(sprintf('aria-label="%s"', __('cms::public.link_tiles.label')))
        ->not->toContain('<h2');
});

test('a tile without a link renders without an anchor', function (): void {
    $page = makePage([
        'slug' => 'tiles',
    ]);
    addBlock($page, 'link_tiles', [
        'tiles' => [
            [
                'name' => 'Buurtvereniging',
            ],
        ],
    ]);

    $html = linkTilesHtml((string) $this->get('/tiles')->assertOk()->getContent());

    expect($html)
        ->toContain('<div class="cms-link-tiles__tile">')
        ->toContain('<span class="cms-link-tiles__name">Buurtvereniging</span>')
        ->not->toContain('<a ');
});

test('each block chooses its own rel value', function (string $rel): void {
    $page = makePage([
        'slug' => 'links',
    ]);
    addBlock($page, 'link_tiles', [
        'rel' => $rel,
        'tiles' => [
            [
                'name' => 'GitHub',
                'url' => 'https://github.com/J87NL',
            ],
        ],
    ]);

    $this->get('/links')->assertSee(sprintf('rel="%s"', $rel), false);
})->with([
    'noopener',
    'me noopener',
    'sponsored noopener',
]);

test('the rel value defaults to noopener', function (): void {
    $page = makePage([
        'slug' => 'links',
    ]);
    $block = addBlock($page, 'link_tiles', [
        'tiles' => [
            [
                'name' => 'GitHub',
                'url' => 'https://github.com/J87NL',
            ],
        ],
    ]);

    expect($block->data['rel'])->toBe('noopener');

    $this->get('/links')->assertSee('rel="noopener"', false);
});

test('only https links pass validation', function (string $url, bool $passes): void {
    expect(linkTilesPasses([
        'tiles' => [
            [
                'name' => 'Link',
                'url' => $url,
            ],
        ],
    ]))->toBe($passes);
})->with([
    ['https://example.com/page', true],
    ['http://example.com', false],
    ['javascript:alert(1)', false],
    ['https:evil.example', false],
    ['//evil.example', false],
    ['/contact', false],
    ['mailto:me@example.com', false],
    ['https://exa mple.com', false],
    ['HTTPS://EXAMPLE.COM', false],
    [' https://example.com', false],
]);

test('a link is stored without surrounding spaces', function (): void {
    $block = addBlock(makePage(), 'link_tiles', [
        'tiles' => [
            [
                'name' => 'Link',
                'url' => 'https://example.com/page   ',
            ],
        ],
    ]);

    expect($block->data['tiles'][0]['url'])->toBe('https://example.com/page');
});

test('a tile needs a name, and the list needs at least one tile', function (array $data): void {
    expect(linkTilesPasses($data))->toBeFalse();
})->with([
    'no tiles' => [['tiles' => []]],
    'no name' => [['tiles' => [['url' => 'https://example.com']]]],
]);

test('only the supported rel values pass validation', function (string $rel, bool $passes): void {
    expect(linkTilesPasses([
        'rel' => $rel,
        'tiles' => [
            [
                'name' => 'Link',
            ],
        ],
    ]))->toBe($passes);
})->with([
    ['noopener', true],
    ['me noopener', true],
    ['sponsored noopener', true],
    ['nofollow', false],
    ['me', false],
]);

test('a tile can only use existing media', function (): void {
    expect(linkTilesPasses([
        'tiles' => [
            [
                'name' => 'Link',
                'media_id' => 999,
            ],
        ],
    ]))->toBeFalse();
});

test('a tampered stored link or rel is not rendered', function (): void {
    $page = makePage([
        'slug' => 'tampered',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'rel' => 'opener" onclick="alert(1)',
                'tiles' => [
                    [
                        'name' => 'Evil',
                        'url' => 'javascript:alert(1)',
                    ],
                    [
                        'name' => 'Plain',
                        'url' => 'http://insecure.example',
                    ],
                ],
            ],
        ]);

    $html = linkTilesHtml((string) $this->get('/tampered')->assertOk()->getContent());

    expect($html)
        ->toContain('<span class="cms-link-tiles__name">Evil</span>')
        ->toContain('<span class="cms-link-tiles__name">Plain</span>')
        ->not->toContain('<a ')
        ->and($this->get('/tampered')->getContent())
        ->not->toContain('javascript:')
        ->not->toContain('insecure.example')
        ->not->toContain('onclick');
});

test('a tampered stored link is only rendered when it is a lowercase https link', function (): void {
    $page = makePage([
        'slug' => 'tampered',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'tiles' => [
                    [
                        'name' => 'Upper',
                        'url' => 'HTTPS://UPPER.EXAMPLE',
                    ],
                    [
                        'name' => 'Padded',
                        'url' => '  https://padded.example  ',
                    ],
                ],
            ],
        ]);

    expect(linkTilesHtml((string) $this->get('/tampered')->getContent()))
        ->not->toContain('UPPER.EXAMPLE')
        ->toContain('href="https://padded.example"');
});

test('a tampered stored rel falls back to noopener', function (): void {
    $page = makePage([
        'slug' => 'tampered',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'rel' => 'opener',
                'tiles' => [
                    [
                        'name' => 'Link',
                        'url' => 'https://example.com',
                    ],
                ],
            ],
        ]);

    $this->get('/tampered')->assertSee('rel="noopener"', false);
});

test('media used by a tile cannot be deleted', function (): void {
    $asset = uploadImage();
    addBlock(makePage(), 'link_tiles', [
        'tiles' => [
            [
                'name' => 'Sponsor',
                'media_id' => $asset->id,
            ],
        ],
    ]);

    expect(fn () => app(DeleteMedia::class)->handle($asset))->toThrow(ValidationException::class)
        ->and(MediaAsset::query()
            ->whereKey($asset->id)
            ->exists())->toBeTrue();
});

test('media used on several tiles is listed once', function (): void {
    $type = app(BlockRegistry::class)->find('link_tiles');

    expect($type->mediaIds([
        'tiles' => [
            [
                'name' => 'One',
                'media_id' => 5,
            ],
            [
                'name' => 'Two',
                'media_id' => '5',
            ],
            [
                'name' => 'Three',
                'media_id' => 7,
            ],
            [
                'name' => '',
                'media_id' => 9,
            ],
        ],
    ]))->toBe([5, 7]);
});

test('the tile names count as the block text', function (): void {
    $type = app(BlockRegistry::class)->find('link_tiles');

    expect($type->toPlainText([
        'title' => 'Sponsors',
        'tiles' => [
            [
                'name' => 'Bakkerij',
            ],
            [
                'name' => 'Garage',
            ],
        ],
    ]))->toBe('Sponsors Bakkerij, Garage');
});

test('the MCP block summary describes the tiles', function (): void {
    app()->setLocale('en');

    expect(app(BlockTypeSummary::class)->text())
        ->toContain('link_tiles "Link tiles" (title, rel, tiles*: list of {name*, media_id, url})');
});

test('an agent adds a link tiles block over MCP', function (): void {
    hubGrant(CmsTools::FULL_GRANT);
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, [
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'rel' => 'me noopener',
                'tiles' => [
                    [
                        'name' => 'GitHub',
                        'url' => 'https://github.com/J87NL',
                    ],
                ],
            ],
        ])
        ->assertOk();

    $block = Block::query()
        ->where('page_id', $page->id)
        ->firstOrFail();

    expect($block->type)->toBe('link_tiles')
        ->and($block->data)->toBe([
            'title' => '',
            'rel' => 'me noopener',
            'tiles' => [
                [
                    'name' => 'GitHub',
                    'media_id' => null,
                    'url' => 'https://github.com/J87NL',
                ],
            ],
        ]);
});

test('an agent cannot add a tile with an unsafe link over MCP', function (): void {
    hubGrant(CmsTools::FULL_GRANT);
    $page = makePage(['title' => 'Concept'], PageStatus::Draft);

    CmsTestServer::actingAs(cmsUser(CmsPermission::PagesEdit))
        ->tool(BlockAddTool::class, [
            'page_id' => $page->id,
            'type' => 'link_tiles',
            'data' => [
                'tiles' => [
                    [
                        'name' => 'Evil',
                        'url' => 'javascript:alert(1)',
                    ],
                ],
            ],
        ])
        ->assertHasErrors([__('cms::validation.unsafe_link')]);

    expect(Block::query()
        ->count())->toBe(0);
});
