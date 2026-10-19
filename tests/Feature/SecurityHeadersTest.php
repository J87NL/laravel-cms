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
        ->toContain("frame-src 'none'")
        ->toContain("img-src 'self';")
        ->not->toContain('data:');
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

    $this->get('/video')->assertOk();

    foreach ([1, 2] as $request) {
        expect($this->get('/video')->headers->get('Content-Security-Policy'))
            ->toContain('frame-src https://www.youtube-nocookie.com')
            ->not->toContain('openstreetmap');
    }
});

test('embed hosts are added to a configured frame source', function (): void {
    config()->set('cms.security_headers.csp', [
        'frame-src' => ['https://player.example.org'],
        'frame-ancestors' => ["'self'"],
    ]);

    $page = makePage([
        'title' => 'Map',
        'slug' => 'map',
    ]);

    addBlock($page, 'embed', [
        'url' => 'https://www.openstreetmap.org/export/embed.html',
        'title' => 'Map',
    ]);

    expect($this->get('/map')->headers->get('Content-Security-Policy'))
        ->toContain('frame-src https://player.example.org https://www.openstreetmap.org')
        ->toContain("frame-ancestors 'self'")
        ->not->toContain('youtube');
});

test('secure requests get hsts', function (): void {
    makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);

    $this->get('https://localhost/about')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('each non csp header can be switched off', function (string $setting, string $header): void {
    makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);

    $this->get('https://localhost/about')->assertHeader($header);

    config()->set(sprintf('cms.security_headers.%s', $setting), false);

    $this->get('https://localhost/about')
        ->assertHeaderMissing($header)
        ->assertHeader('Content-Security-Policy');
})->with([
    'hsts' => ['hsts', 'Strict-Transport-Security'],
    'nosniff' => ['nosniff', 'X-Content-Type-Options'],
    'referrer policy' => ['referrer_policy', 'Referrer-Policy'],
    'permissions policy' => ['permissions_policy', 'Permissions-Policy'],
]);
