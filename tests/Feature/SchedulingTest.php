<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Actions\MakeHomePage;
use J87NL\Cms\Actions\RecordRevision;
use J87NL\Cms\Actions\SchedulePage;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Http\Controllers\PreviewController;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\HomePageLock;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;

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

test('scheduling and the scheduler work when the app uses immutable dates', function (): void {
    Date::use(CarbonImmutable::class);

    try {
        makePage(['title' => 'Welcome', 'slug' => 'welcome'], home: true);
        $draft = makePage(['title' => 'Coming soon', 'slug' => 'soon'], PageStatus::Draft);
        $live = makePage(['title' => 'Ending', 'slug' => 'ending']);

        app(SchedulePage::class)->handle($draft, now()->addHour(), now()->addHours(3));
        app(SchedulePage::class)->handle($live, null, now()->addHours(2));

        expect($draft->refresh()->publish_at)->toBeInstanceOf(CarbonImmutable::class);

        $this->travel(61)->minutes();
        $this->artisan('cms:publish-scheduled')->assertSuccessful();

        expect($draft->refresh()->status)->toBe(PageStatus::Published);

        $this->travel(60)->minutes();
        $this->artisan('cms:publish-scheduled')->assertSuccessful();

        expect($live->refresh()->status)->not->toBe(PageStatus::Published);
    } finally {
        Date::useDefault();
    }
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

test('a refused scheduled change keeps its moment and tells the editor why', function (): void {
    Log::spy();

    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $page = makePage([
        'title' => 'Ending',
        'slug' => 'ending',
    ]);

    app(SchedulePage::class)->handle($page, null, now()->addHour());
    app(MakeHomePage::class)->handle($page->refresh());

    $this->travel(61)->minutes();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();

    expect($page->refresh())
        ->status->toBe(PageStatus::Published)
        ->unpublish_at->not->toBeNull()
        ->schedule_error->toBe(__('cms::validation.unpublish_home'));

    Log::shouldHaveReceived('warning')->once();

    app(SchedulePage::class)->handle($page, null, null);

    expect($page->refresh()->schedule_error)->toBeNull();
});

test('refusals in both directions are saved once and not rewritten every run', function (): void {
    Log::spy();

    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $page = makePage([
        'title' => 'Ending',
        'slug' => 'ending',
    ]);

    $refusing = new class(app(RecordRevision::class), app(HomePageLock::class), app(PageVisibility::class), app(PendingRevisionEditor::class), app(PendingRevisionApplier::class)) extends ChangePageStatus
    {
        public function handle(Page $page, PageStatus $status, ?int $userId): void
        {
            throw ValidationException::withMessages([
                'page' => sprintf('No %s.', $status->value),
            ]);
        }
    };
    $this->app->instance(ChangePageStatus::class, $refusing);

    $page->publish_at = now()->addMinute();
    $page->unpublish_at = now()->addMinutes(2);
    $page->saveQuietly();

    $this->travel(3)->minutes();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();

    $updatedAt = $page->refresh()->updated_at;

    $this->travel(1)->minutes();
    $this->artisan('cms:publish-scheduled')->assertSuccessful();

    expect($page->refresh())
        ->schedule_error->toBe('No published. No unpublished.')
        ->updated_at->toEqual($updatedAt);

    Log::shouldHaveReceived('warning')->once();
});

test('one failing page does not stop the others, fails the run and is reported once', function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $broken = makePage([
        'title' => 'Broken',
        'slug' => 'broken',
    ], PageStatus::Draft);
    $fine = makePage([
        'title' => 'Fine',
        'slug' => 'fine',
    ], PageStatus::Draft);

    app(SchedulePage::class)->handle($broken, now()->addHour(), null);
    app(SchedulePage::class)->handle($fine, now()->addHour(), null);

    $failing = new class(app(RecordRevision::class), app(HomePageLock::class), app(PageVisibility::class), app(PendingRevisionEditor::class), app(PendingRevisionApplier::class)) extends ChangePageStatus
    {
        public int $failFor = 0;

        public function handle(Page $page, PageStatus $status, ?int $userId): void
        {
            if ($page->id === $this->failFor) {
                throw new RuntimeException('Database hiccup');
            }

            parent::handle($page, $status, $userId);
        }
    };
    $failing->failFor = $broken->id;
    $this->app->instance(ChangePageStatus::class, $failing);

    Exceptions::fake();

    $this->travel(61)->minutes();
    $this->artisan('cms:publish-scheduled')->assertFailed();
    $this->artisan('cms:publish-scheduled')->assertFailed();

    Exceptions::assertReportedCount(1);

    expect($broken->refresh())
        ->status->toBe(PageStatus::Draft)
        ->publish_at->not->toBeNull()
        ->and($fine->refresh()->status)->toBe(PageStatus::Published);
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
