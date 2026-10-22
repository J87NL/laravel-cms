<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\HomePageLock;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;

class PublishPage
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
        private readonly ChangePageStatus $changePageStatus,
        private readonly PendingRevisionEditor $pending,
        private readonly PendingRevisionApplier $applier,
        private readonly HomePageLock $homePageLock,
    ) {}

    public function handle(Page $page, ?int $userId): void
    {
        $this->homePageLock->run(function () use ($page, $userId): void {
            DB::transaction(function () use ($page, $userId): void {
                $locked = Page::query()
                    ->lockForUpdate()
                    ->findOrFail($page->id);

                $this->publishLocked($locked, $userId);

                $page->setRawAttributes($locked->getAttributes(), true);
            });
        });
    }

    private function publishLocked(Page $page, ?int $userId): void
    {
        if (! is_null($page->publish_at)) {
            throw ValidationException::withMessages([
                'page' => __('cms::validation.publish_scheduled'),
            ]);
        }

        $revision = $this->pending->find($page);

        if (is_null($revision) && $page->isPublished()) {
            throw ValidationException::withMessages([
                'page' => __('cms::validation.nothing_to_publish'),
            ]);
        }

        if (! is_null($revision)) {
            $this->applier->apply($page, $revision);
        }

        if (! $page->isPublished()) {
            $this->changePageStatus->applyLocked($page, PageStatus::Published, $userId);

            return;
        }

        $this->recordRevision->handle($page, $userId);
    }
}
