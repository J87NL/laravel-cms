<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ImportRedirects;
use J87NL\Cms\Actions\SavePage;
use J87NL\Cms\Actions\SaveRedirect;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Enums\RedirectSource;
use J87NL\Cms\Models\Redirect;

test('a slug change redirects the page and its whole subtree', function (): void {
    $parent = makePage([
        'title' => 'Projects',
        'slug' => 'projects',
    ]);
    makePage([
        'title' => 'Site',
        'slug' => 'site',
        'parent_id' => $parent->id,
    ]);

    app(SavePage::class)->handle($parent, [
        'title' => 'Work',
        'slug' => 'work',
    ], null);

    expect(Redirect::query()->pluck('to', 'from_path')->all())->toBe([
        'projects' => '/work',
        'projects/site' => '/work/site',
    ]);

    $this->get('/projects/site?ref=x')->assertStatus(301)->assertRedirect('/work/site?ref=x');
    $this->get('/work/site')->assertOk();
});

test('redirect chains collapse to one hop', function (): void {
    $page = makePage([
        'title' => 'A',
        'slug' => 'a',
    ]);

    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'b'], null);
    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'c'], null);

    expect(Redirect::query()->orderBy('from_path')->pluck('to', 'from_path')->all())->toBe([
        'a' => '/c',
        'b' => '/c',
    ]);
});

test('moving a page back to an old path removes the redirect from that path', function (): void {
    $page = makePage([
        'title' => 'A',
        'slug' => 'a',
    ]);

    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'b'], null);
    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'a'], null);

    expect(Redirect::query()->pluck('to', 'from_path')->all())->toBe([
        'b' => '/a',
    ]);

    $this->get('/a')->assertOk();
});

test('a published page wins over a redirect and redirects count their hits', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/old', '/new', 302, RedirectSource::Manual);

    $this->get('/old')->assertStatus(302)->assertRedirect('/new');
    $this->get('/OLD/')->assertStatus(302);

    expect(Redirect::query()->first())
        ->hits->toBe(2)
        ->last_hit_at->not->toBeNull();
});

test('a redirect from a hidden page path still applies', function (): void {
    makePage([
        'title' => 'Hidden',
        'slug' => 'hidden',
    ], PageStatus::Draft);

    $redirect = new Redirect([
        'from_path' => 'hidden',
        'to' => '/elsewhere',
    ]);
    $redirect->save();

    $this->get('/hidden')->assertRedirect('/elsewhere');
});

test('manual redirects refuse the home page, page paths, duplicates and loops', function (string $from, string $to): void {
    makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);
    app(SaveRedirect::class)->handle(new Redirect, '/taken', '/about', 301, RedirectSource::Manual);
    app(SaveRedirect::class)->handle(new Redirect, '/step', '/loop', 301, RedirectSource::Manual);

    app(SaveRedirect::class)->handle(new Redirect, $from, $to, 301, RedirectSource::Manual);
})->with([
    'home' => ['/', '/about'],
    'page' => ['/about', '/elsewhere'],
    'duplicate' => ['/taken', '/elsewhere'],
    'self' => ['/self', '/self'],
    'loop' => ['/loop', '/step'],
])->throws(ValidationException::class);

test('full urls on this site are stored as paths and external targets stay absolute', function (): void {
    config()->set('app.url', 'https://j87.nl');

    $local = app(SaveRedirect::class)->handle(new Redirect, 'https://www.j87.nl/Oud/?x=1', 'https://j87.nl/nieuw', 301, RedirectSource::Manual);
    $external = app(SaveRedirect::class)->handle(new Redirect, '/elders', 'https://example.org/page', 301, RedirectSource::Manual);

    expect($local->from_path)->toBe('oud')
        ->and($local->to)->toBe('/nieuw')
        ->and($external->to)->toBe('https://example.org/page');
});

test('a csv import creates and updates redirects and reports bad lines', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/existing', '/first', 301, RedirectSource::Manual);

    $file = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($file, implode("\n", [
        'van;naar;status',
        '/oud;/nieuw;302',
        '/existing;/second',
        ';/missing',
        '/bad;/x;307',
        '/;/home',
    ]));

    $result = app(ImportRedirects::class)->handle($file);

    expect($result->imported)->toBe(2)
        ->and(array_keys($result->errors))->toBe([4, 5, 6])
        ->and(Redirect::query()->where('from_path', 'oud')->first())
        ->status_code->toBe(302)
        ->source->toBe(RedirectSource::Import)
        ->and(Redirect::query()->where('from_path', 'existing')->value('to'))->toBe('/second');
});
