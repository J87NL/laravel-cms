# Scheduling

A page can be published or unpublished at a planned moment. The moments are stored in `publish_at` and
`unpublish_at`, and the `cms:publish-scheduled` command applies them.

## Planning a change

```php
use Illuminate\Support\Carbon;
use J87NL\Cms\Actions\SchedulePage;

app(SchedulePage::class)->handle($page, publishAt: Carbon::parse('2026-11-01 09:00'), unpublishAt: null);
```

`SchedulePage::handle(Page $page, ?Carbon $publishAt, ?Carbon $unpublishAt): void` stores both moments and clears a
previous `schedule_error`. Pass `null` to clear a moment.

- A publish moment for a page that is already published is ignored.
- A moment in the past is refused.
- An unpublish moment must come after the publish moment.
- An unpublish moment is refused for the home page and for pages above it.

Refusals are a `ValidationException` on `publish_at` or `unpublish_at`.

## Running the schedule

The service provider schedules the command every minute, without overlapping:

```php
$schedule->command(PublishScheduledPages::class)
    ->everyMinute()
    ->withoutOverlapping();
```

This only runs when the Laravel scheduler runs (for example `php artisan schedule:run` from cron). Set
`cms.scheduling.enabled` to `false` to leave the command out of the schedule; you can still run it yourself.

## What the command does

`cms:publish-scheduled` takes every page whose `publish_at` or `unpublish_at` has passed and changes its status with
`ChangePageStatus`, without a user. It prints one line per change.

- After a successful change, the moment is cleared.
- When the change is refused (for example unpublishing a page that has become the home page), the moment is kept, the
  reason is stored in `schedule_error` and a warning is logged. The admin can show `schedule_error` to the editor.
  The command tries again every minute, but stores and logs the same reason only once.
- When a page fails with an unexpected exception, the other pages are still processed, the command exits with a
  failure code and the exception is reported. The same failure of the same page is reported at most once per 24
  hours.

Publishing through the schedule follows the same rules as publishing by hand: the first public page becomes the home
page, and a revision is recorded.
