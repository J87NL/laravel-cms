<?php

declare(strict_types=1);

namespace J87NL\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;
use Throwable;

class PublishScheduledPages extends Command
{
    private const int FAILURE_REPORT_SECONDS = 86400;

    protected $signature = 'cms:publish-scheduled';

    protected $description = 'Publish and unpublish pages whose scheduled moment has passed';

    public function handle(ChangePageStatus $changePageStatus): int
    {
        $due = Page::query()
            ->where(fn ($query) => $query
                ->where('publish_at', '<=', now())
                ->orWhere('unpublish_at', '<=', now()))
            ->orderBy('id')
            ->get();

        $failed = false;

        foreach ($due as $page) {
            try {
                $this->process($page, $changePageStatus);
            } catch (Throwable $exception) {
                $failed = true;

                $this->reportFailure($page, $exception);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function process(Page $page, ChangePageStatus $changePageStatus): void
    {
        $reasons = [];
        $changed = false;

        if ($this->isDue($page->publish_at)) {
            $reason = $this->change($page, PageStatus::Published, $changePageStatus);
            $reasons[] = $reason;

            if (is_null($reason)) {
                $page->publish_at = null;
                $changed = true;
            }
        }

        if ($this->isDue($page->unpublish_at)) {
            $reason = $this->change($page, PageStatus::Unpublished, $changePageStatus);
            $reasons[] = $reason;

            if (is_null($reason)) {
                $page->unpublish_at = null;
                $changed = true;
            }
        }

        $error = implode(' ', array_filter($reasons)) ?: null;

        if ($error !== $page->schedule_error) {
            $this->recordError($page, $error);

            $changed = true;
        }

        if ($changed) {
            $page->save();
        }
    }

    private function isDue(?Carbon $moment): bool
    {
        return ! is_null($moment) && $moment->lessThanOrEqualTo(now());
    }

    private function change(Page $page, PageStatus $status, ChangePageStatus $changePageStatus): ?string
    {
        try {
            $changePageStatus->handle($page, $status, null);
        } catch (ValidationException $exception) {
            $reason = (string) collect($exception->errors())->flatten()->first();

            $this->warn(sprintf('Skipped /%s: %s', $page->path, $reason));

            return $reason;
        }

        $this->info(sprintf('%s: /%s', $status->value, $page->path));

        return null;
    }

    private function recordError(Page $page, ?string $error): void
    {
        $page->schedule_error = $error;

        if (is_null($error)) {
            return;
        }

        Log::warning('A scheduled page change was refused.', [
            'page_id' => $page->id,
            'path' => $page->path,
            'reason' => $error,
        ]);
    }

    private function reportFailure(Page $page, Throwable $exception): void
    {
        $this->error(sprintf('Failed /%s: %s', $page->path, $exception->getMessage()));

        $key = sprintf('cms:publish-scheduled:failure:%d:%s', $page->id, sha1(sprintf('%s|%s', $exception::class, $exception->getMessage())));

        if (Cache::add($key, true, self::FAILURE_REPORT_SECONDS)) {
            report($exception);
        }
    }
}
