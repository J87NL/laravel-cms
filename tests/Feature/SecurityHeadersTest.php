<?php

declare(strict_types=1);

test('public pages send a strict content security policy and privacy headers', function (): void {
    makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);

    $response = $this->get('/about')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Strict-Transport-Security');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("script-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("frame-src 'none'");
});

test('pages with an embed allow only the embed hosts in frames', function (): void {
    $page = makePage([
        'title' => 'Video',
        'slug' => 'video',
    ]);

    addBlock($page, 'embed', [
        'url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'title' => 'Video',
    ]);

    foreach ([1, 2] as $request) {
        expect($this->get('/video')->headers->get('Content-Security-Policy'))
            ->toContain('frame-src https://www.youtube-nocookie.com https://www.openstreetmap.org');
    }
});

test('secure requests get hsts', function (): void {
    makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);

    $this->get('https://localhost/about')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});
