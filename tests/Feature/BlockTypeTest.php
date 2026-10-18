<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use J87NL\Cms\Blocks\AbstractBlockType;
use J87NL\Cms\Blocks\BlockContext;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Facades\Cms;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Rules\SafeLink;

test('embeds outside the allowlist are rejected', function (string $url, bool $allowed): void {
    $type = app(BlockRegistry::class)->find('embed');

    $validator = Validator::make([
        'url' => $url,
        'title' => 'Video',
    ], $type->rules());

    expect($validator->passes())->toBe($allowed);
})->with([
    ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', true],
    ['https://www.openstreetmap.org/export/embed.html?bbox=4.1,51.8,4.2,51.9&layer=mapnik', true],
    ['https://www.youtube.com/embed/dQw4w9WgXcQ', false],
    ['http://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false],
    ['https://www.youtube-nocookie.com.evil.example/embed/x', false],
    ['https://evil.example/?https://www.youtube-nocookie.com/embed/x', false],
    ['javascript:alert(1)', false],
]);

test('a disabled block type is not available', function (): void {
    config()->set('cms.blocks.disabled', ['gallery']);

    expect(app(BlockRegistry::class)->has('gallery'))->toBeFalse()
        ->and(app(BlockRegistry::class)->has('image'))->toBeTrue();
});

test('a registered block type renders on its page', function (): void {
    Cms::blocks()->register(new class extends AbstractBlockType
    {
        public function key(): string
        {
            return 'shout';
        }

        public function fields(): array
        {
            return [];
        }

        public function view(): string
        {
            return 'cms-test::shout';
        }

        public function viewData(array $data, BlockContext $context): array
        {
            return [
                'text' => strtoupper((string) $data['text']),
            ];
        }

        public function toPlainText(array $data): string
        {
            return (string) $data['text'];
        }
    });
    view()->addNamespace('cms-test', __DIR__.'/../fixtures/views');

    $page = makePage([
        'slug' => 'loud',
    ]);
    addBlock($page, 'shout', [
        'text' => 'hello',
    ]);

    $this->get('/loud')->assertSee('HELLO');
});

test('hidden blocks are not rendered', function (): void {
    $page = makePage([
        'slug' => 'about',
    ]);
    $block = addBlock($page, 'rich_text', [
        'html' => '<p>Secret</p>',
    ]);
    $block->update([
        'is_visible' => false,
    ]);

    $this->get('/about')->assertDontSee('Secret');
});

test('strict embed patterns reject path tricks', function (string $url): void {
    $validator = Validator::make([
        'url' => $url,
        'title' => 'Video',
    ], app(BlockRegistry::class)->find('embed')->rules());

    expect($validator->passes())->toBeFalse();
})->with([
    'https://www.youtube-nocookie.com/embed/../watch?v=1',
    'https://www.youtube-nocookie.com/embed/%2e%2e/watch',
    'https://www.youtube-nocookie.com/embed/%2E%2E/watch',
    'https://www.youtube-nocookie.com/embed/abc',
    'https://youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'https://www.openstreetmap.org/export/embed.html.evil',
    'https://www.openstreetmap.org/export/embed.html/x',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ#x',
]);

test('a tampered stored embed or link is not rendered', function (): void {
    $page = makePage([
        'slug' => 'tampered',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'embed',
            'data' => [
                'url' => 'https://evil.example/embed',
                'title' => 'Evil',
            ],
        ]);
    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'call_to_action',
            'data' => [
                'title' => 'Go',
                'text' => '',
                'button_label' => 'Click',
                'url' => 'javascript:alert(1)',
            ],
        ]);

    $this->get('/tampered')
        ->assertOk()
        ->assertDontSee('evil.example', false)
        ->assertDontSee('javascript:', false)
        ->assertDontSee('Click');
});

test('links must be safe paths or safe schemes', function (string $link, bool $passes): void {
    expect(SafeLink::passes($link))->toBe($passes);
})->with([
    ['/contact', true],
    ['https://example.com', true],
    ['mailto:me@example.com', true],
    ['//evil.example', false],
    ['https:evil.example', false],
    ['http:/evil.example', false],
    ['/\\evil.example', false],
    ['\\\\evil.example', false],
    ['javascript:alert(1)', false],
]);
