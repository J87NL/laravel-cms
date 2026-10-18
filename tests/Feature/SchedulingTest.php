<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\SchedulePage;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Http\Controllers\PreviewController;

test('the scheduler publishes and unpublishes pages when their moment has passed', function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $draft = makePage([
        'title' => 'Coming soon',
        'slug' => 'soon',
    ], PageStatus::Draft);
    $live = makePage([
        'title' => 'Ending',
        'slug' => 'ending',
    ]);

    app(SchedulePage::class)->handle($draft, now()->addHour(), now()->addHours(3));
    app(SchedulePage::class)->handle($live, null, now()->addHours(2));

    $this->get('/soon')->assertNotFound();

    $this->travel(61)->minutes();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();

    expect($draft->refresh())
        ->status->toBe(PageStatus::Published)
        ->publish_at->toBeNull()
        ->unpublish_at->not->toBeNull();
    $this->get('/soon')->assertOk();
    $this->get('/ending')->assertOk();

    $this->travel(2)->hours();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();

    expect($draft->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and($live->refresh()->status)->toBe(PageStatus::Unpublished)
        ->and($live->unpublish_at)->toBeNull();
    $this->get('/soon')->assertNotFound();
    $this->get('/ending')->assertNotFound();
});

test('a schedule cannot lie in the past, end before it starts or take the home page offline', function (Closure $schedule): void {
    $home = makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $draft = makePage([
        'title' => 'Draft',
        'slug' => 'draft',
    ], PageStatus::Draft);

    $schedule($home, $draft);
})->with([
    'past' => [fn ($home, $draft) => app(SchedulePage::class)->handle($draft, now()->subMinute(), null)],
    'reversed' => [fn ($home, $draft) => app(SchedulePage::class)->handle($draft, now()->addDay(), now()->addHour())],
    'home' => [fn ($home, $draft) => app(SchedulePage::class)->handle($home, null, now()->addDay())],
])->throws(ValidationException::class);

test('the scheduler runs every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'cms:publish-scheduled'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

test('a signed preview renders a draft without letting it be indexed', function (): void {
    $draft = makePage([
        'title' => 'Secret draft',
        'slug' => 'secret',
    ], PageStatus::Draft);

    addBlock($draft, 'rich_text', [
        'html' => '<p>Not yet public</p>',
    ]);

    $this->get(PreviewController::url($draft))
        ->assertOk()
        ->assertSee('Not yet public', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeaderMissing('X-Cms-Cache');

    $this->get('/secret')->assertNotFound();
});

test('a preview link needs a valid signature and expires after an hour', function (): void {
    $draft = makePage([
        'title' => 'Secret draft',
        'slug' => 'secret',
    ], PageStatus::Draft);

    $url = PreviewController::url($draft);

    $this->get(sprintf('/cms/preview/%d', $draft->id))->assertForbidden();

    $this->travel(61)->minutes();

    $this->get($url)->assertForbidden();
});
