<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\HomePageLock;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;

class ChangePageStatus
{
    public function __construct(
        private readonly RecordRevision $recordRevision,
        private readonly HomePageLock $homePageLock,
        private readonly PageVisibility $visibility,
        private readonly PendingRevisionEditor $pending,
        private readonly PendingRevisionApplier $applier,
    ) {}

    public function handle(Page $page, PageStatus $status, ?int $userId): void
    {
        $this->homePageLock->run(function () use ($page, $status, $userId): void {
            DB::transaction(function () use ($page, $status, $userId): void {
                $locked = Page::query()
                    ->lockForUpdate()
                    ->findOrFail($page->id);

                $this->applyLocked($locked, $status, $userId);

                $page->setRawAttributes($locked->getAttributes(), true);
            });
        });
    }

    public function applyLocked(Page $locked, PageStatus $status, ?int $userId): void
    {
        if ($locked->status === $status) {
            return;
        }

        $reason = $status === PageStatus::Published ? null : self::unpublishBlockedReason($locked);

        if (! is_null($reason)) {
            throw ValidationException::withMessages([
                'page' => $reason,
            ]);
        }

        $pending = $status === PageStatus::Published ? null : $this->pending->find($locked);

        if (! is_null($pending)) {
            $this->applier->apply($locked, $pending);
        }

        $locked->status = $status;
        $locked->save();

        $this->assignHomeWhenMissing($locked);

        $this->recordRevision->handle($locked, $userId);
    }

    public static function unpublishBlockedReason(Page $page): ?string
    {
        if ($page->is_home) {
            return __('cms::validation.unpublish_home');
        }

        $homePath = Page::query()
            ->where('is_home', true)
            ->value('path');

        if (is_string($homePath) && str_starts_with($homePath, sprintf('%s/', $page->path))) {
            return __('cms::validation.unpublish_home_ancestor');
        }

        return null;
    }

    private function assignHomeWhenMissing(Page $page): void
    {
        if (! $page->isPublished()) {
            return;
        }

        $hasHome = Page::query()
            ->where('is_home', true)
            ->exists();

        if ($hasHome || ! $this->visibility->isPublic($page)) {
            return;
        }

        $page->is_home = true;
        $page->save();
    }
}
