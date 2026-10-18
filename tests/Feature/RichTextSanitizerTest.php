<?php

declare(strict_types=1);

use J87NL\Cms\Html\RichTextSanitizer;

test('it strips scripts, iframes, style and event attributes', function (): void {
    $html = app(RichTextSanitizer::class)->sanitize(<<<'HTML'
        <h2 onclick="alert(1)">Title</h2>
        <script>alert(1)</script>
        <style>p { color: red; }</style>
        <iframe src="https://evil.example"></iframe>
        <p style="color: red" onmouseover="alert(1)">Text <img src="x" onerror="alert(1)"></p>
        HTML);

    expect($html)
        ->toContain('<h2>Title</h2>')
        ->toContain('<p>Text </p>')
        ->not->toContain('script')
        ->not->toContain('alert')
        ->not->toContain('iframe')
        ->not->toContain('style')
        ->not->toContain('<img')
        ->not->toContain('onclick');
});

test('it keeps the allowed structure and text of blocked wrappers', function (): void {
    $html = app(RichTextSanitizer::class)->sanitize(
        '<div><h3>A</h3><ul><li><strong>b</strong></li></ul><ol><li><em>c</em></li></ol><blockquote>d</blockquote><table><thead><tr><th>e</th></tr></thead><tbody><tr><td colspan="2">f</td></tr></tbody></table><span>g</span></div>',
    );

    expect($html)->toBe('<h3>A</h3><ul><li><strong>b</strong></li></ul><ol><li><em>c</em></li></ol><blockquote>d</blockquote><table><thead><tr><th>e</th></tr></thead><tbody><tr><td colspan="2">f</td></tr></tbody></table>g');
});

test('links keep safe schemes only and open new tabs with noopener', function (): void {
    $sanitizer = app(RichTextSanitizer::class);

    expect($sanitizer->sanitize('<a href="https://example.com" target="_blank">x</a>'))
        ->toContain('href="https://example.com"')
        ->toContain('target="_blank"')
        ->toContain('rel="noopener"')
        ->and($sanitizer->sanitize('<a href="mailto:me@example.com">x</a>'))->toContain('href="mailto:me&#64;example.com"')
        ->and($sanitizer->sanitize('<a href="tel:+31612345678">x</a>'))->toContain('href="tel:&#43;31612345678"')
        ->and($sanitizer->sanitize('<a href="/contact">x</a>'))->toContain('href="/contact"')
        ->and($sanitizer->sanitize('<a href="javascript:alert(1)">x</a>'))->not->toContain('javascript')
        ->and($sanitizer->sanitize('<a href="//evil.example/x">x</a>'))->not->toContain('evil.example')
        ->and($sanitizer->sanitize('<a href="https:evil.example">x</a>'))->not->toContain('evil.example')
        ->and($sanitizer->sanitize('<a href="/\\evil.example">x</a>'))->not->toContain('evil.example')
        ->and($sanitizer->sanitize('<a href="https://example.com" target="_top">x</a>'))->not->toContain('_top');
});
