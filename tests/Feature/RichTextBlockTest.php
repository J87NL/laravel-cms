<?php

declare(strict_types=1);

use J87NL\Cms\Models\Block;

test('rich text is sanitized when a block is saved', function (): void {
    $page = makePage();

    $block = addBlock($page, 'rich_text', [
        'html' => '<p onclick="steal()">Hello</p><script>steal()</script><iframe src="https://evil.example"></iframe><p style="color:red">World</p>',
    ]);

    expect($block->fresh()->data['html'])->toBe('<p>Hello</p><p>World</p>');
});

test('rich text is sanitized again when the page is rendered', function (): void {
    $page = makePage([
        'slug' => 'about',
    ]);

    Block::query()
        ->forceCreate([
            'page_id' => $page->id,
            'type' => 'rich_text',
            'data' => [
                'html' => '<p onmouseover="steal()">Hello</p><script>steal()</script><style>body{}</style><iframe src="https://evil.example"></iframe>',
            ],
        ]);

    $response = $this->get('/about')->assertOk();

    expect($response->getContent())
        ->toContain('<p>Hello</p>')
        ->not->toContain('steal()')
        ->not->toContain('<iframe')
        ->not->toContain('<style');
});
