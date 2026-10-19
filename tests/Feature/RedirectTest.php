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

    expect(redirectTargets())->toBe([
        'projects' => '/work',
        'projects/site' => '/work/site',
    ]);

    $this->get('/projects/site?ref=x')->assertStatus(301)->assertRedirect('/work/site?ref=x');
    $this->get('/work/site')->assertOk();
});

test('renaming a draft or a page below a draft leaks no redirect', function (): void {
    $draft = makePage([
        'title' => 'Secret plans',
        'slug' => 'secret-plans',
    ], PageStatus::Draft);
    $child = makePage([
        'title' => 'Child',
        'slug' => 'child',
        'parent_id' => $draft->id,
    ]);

    app(SavePage::class)->handle($draft, [
        'title' => 'Plans',
        'slug' => 'plans',
    ], null);
    app(SavePage::class)->handle($child->refresh(), [
        'title' => 'Child',
        'slug' => 'kid',
        'parent_id' => $draft->id,
    ], null);

    expect(redirectTargets())->toBe([]);

    $this->get('/secret-plans')->assertNotFound();
    $this->get('/secret-plans/child')->assertNotFound();
});

test('moving a public page below a draft leaves the old address a plain not found', function (): void {
    $draft = makePage([
        'title' => 'Secret plans',
        'slug' => 'secret-plans',
    ], PageStatus::Draft);
    $published = makePage([
        'title' => 'Public',
        'slug' => 'public',
    ]);

    app(SavePage::class)->handle($published, [
        'title' => 'Public',
        'slug' => 'public',
        'parent_id' => $draft->id,
    ], null);

    expect(redirectTargets())->toBe([]);

    $this->get('/public')
        ->assertNotFound()
        ->assertHeaderMissing('Location');
});

test('moving a public subtree out of a draft leaks no redirect for its descendants', function (): void {
    $draft = makePage([
        'title' => 'Secret plans',
        'slug' => 'secret-plans',
    ], PageStatus::Draft);
    $parent = makePage([
        'title' => 'P',
        'slug' => 'p',
        'parent_id' => $draft->id,
    ]);
    makePage([
        'title' => 'C',
        'slug' => 'c',
        'parent_id' => $parent->id,
    ]);

    app(SavePage::class)->handle($parent, [
        'title' => 'P',
        'slug' => 'p',
        'parent_id' => null,
    ], null);

    expect(redirectTargets())->toBe([]);

    $this->get('/p/c')->assertOk();
    $this->get('/secret-plans/p/c')->assertNotFound();
});

test('renaming a draft onto a redirected address keeps that redirect', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/plans', '/strategy', 301, RedirectSource::Manual);

    $draft = makePage([
        'title' => 'Draft',
        'slug' => 'draft',
    ], PageStatus::Draft);

    app(SavePage::class)->handle($draft, [
        'title' => 'Plans',
        'slug' => 'plans',
    ], null);

    expect(redirectTargets())->toBe([
        'plans' => '/strategy',
    ]);

    $this->get('/plans')->assertRedirect('/strategy');
});

test('redirect chains collapse to one hop', function (): void {
    $page = makePage([
        'title' => 'A',
        'slug' => 'a',
    ]);

    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'b'], null);
    app(SavePage::class)->handle($page, ['title' => 'A', 'slug' => 'c'], null);

    expect(redirectTargets())->toBe([
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

    expect(redirectTargets())->toBe([
        'b' => '/a',
    ]);

    $this->get('/a')->assertOk();
});

test('a published page wins over a redirect and redirects count their hits', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/old', '/new', 302, RedirectSource::Manual);

    $this->get('/old')->assertStatus(302)->assertRedirect('/new');
    $this->get('/OLD/')->assertStatus(302);

    $redirect = Redirect::query()
        ->first();

    expect($redirect)
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

test('a csv import creates and updates redirects', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/existing', '/first', 301, RedirectSource::Manual);

    $result = app(ImportRedirects::class)->handle(csvFile([
        "\xEF\xBB\xBFvan;naar;status",
        '/oud;/nieuw;302',
        '/existing;/second',
    ]));

    $imported = Redirect::query()
        ->where('from_path', 'oud')
        ->first();

    expect($result->imported)->toBe(2)
        ->and($result->errors)->toBe([])
        ->and($imported)
        ->status_code->toBe(302)
        ->source->toBe(RedirectSource::Import)
        ->and(redirectTargets())->toBe([
            'existing' => '/second',
            'oud' => '/nieuw',
        ]);
});

test('a byte order mark before a first data line is not part of the old address', function (): void {
    $result = app(ImportRedirects::class)->handle(csvFile([
        "\xEF\xBB\xBF/oud,/nieuw",
    ]));

    expect($result->imported)->toBe(1)
        ->and(redirectTargets())->toBe([
            'oud' => '/nieuw',
        ]);
});

test('a csv import with a bad line imports nothing and reports every bad line', function (): void {
    app(SaveRedirect::class)->handle(new Redirect, '/existing', '/first', 301, RedirectSource::Manual);

    $result = app(ImportRedirects::class)->handle(csvFile([
        'van;naar;status',
        '/oud;/nieuw;302',
        '/existing;/second',
        ';/missing',
        '/bad;/x;307',
        '/;/home',
        sprintf('/%s;/x', str_repeat('a', 255)),
        sprintf('/long;/%s', str_repeat('b', 2048)),
        '/script;javascript:alert(1)',
    ]));

    expect($result->imported)->toBe(0)
        ->and(array_keys($result->errors))->toBe([4, 5, 6, 7, 8, 9])
        ->and($result->errors[7])->toBe(__('cms::redirects.import.from_too_long', ['max' => 255]))
        ->and($result->errors[8])->toBe(__('cms::redirects.import.to_too_long', ['max' => 2048]))
        ->and($result->errors[9])->toBe(__('cms::redirects.import.invalid_target'))
        ->and(redirectTargets())->toBe([
            'existing' => '/first',
        ]);
});

/**
 * @param  list<string>  $lines
 */
function csvFile(array $lines): string
{
    $file = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($file, implode("\n", $lines));

    return $file;
}
