<?php

declare(strict_types=1);

namespace J87NL\Cms\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Actions\ChangePageStatus;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;

class PublishScheduledPages extends Command
{
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

        foreach ($due as $page) {
            $this->process($page, $changePageStatus);
        }

        return self::SUCCESS;
    }

    private function process(Page $page, ChangePageStatus $changePageStatus): void
    {
        if (! is_null($page->publish_at) && $page->publish_at->lessThanOrEqualTo(now())) {
            $this->change($page, PageStatus::Published, $changePageStatus);

            $page->publish_at = null;
            $page->save();
        }

        if (! is_null($page->unpublish_at) && $page->unpublish_at->lessThanOrEqualTo(now())) {
            $this->change($page, PageStatus::Unpublished, $changePageStatus);

            $page->unpublish_at = null;
            $page->save();
        }
    }

    private function change(Page $page, PageStatus $status, ChangePageStatus $changePageStatus): void
    {
        try {
            $changePageStatus->handle($page, $status, null);

            $this->info(sprintf('%s: /%s', $status->value, $page->path));
        } catch (ValidationException $exception) {
            $this->warn(sprintf('Skipped /%s: %s', $page->path, (string) collect($exception->errors())->flatten()->first()));
        }
    }
}
