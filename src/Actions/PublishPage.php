<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Pages\HomePageLock;
use J87NL\Cms\Pages\PendingRevisionEditor;

class PublishPage
{
    public function __construct(
        private readonly SavePage $savePage,
        private readonly RestoreRevision $restoreRevision,
        private readonly RecordRevision $recordRevision,
        private readonly ChangePageStatus $changePageStatus,
        private readonly PendingRevisionEditor $pending,
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
            $this->apply($page, $revision);
        }

        if (! $page->isPublished()) {
            $this->changePageStatus->applyLocked($page, PageStatus::Published, $userId);

            return;
        }

        $this->recordRevision->handle($page, $userId);
    }

    private function apply(Page $page, Revision $revision): void
    {
        $snapshot = $revision->snapshot;

        try {
            $this->savePage->apply($page, $this->restoreRevision->pageAttributes($page, $snapshot['page']));
        } catch (UniqueConstraintViolationException) {
            throw SavePage::pathTaken($page);
        }

        $live = Block::query()
            ->where('page_id', $page->id)
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($snapshot['blocks'] as $blockSnapshot) {
            $block = $live->get((int) ($blockSnapshot['id'] ?? 0)) ?? new Block;

            $block->fill([
                'type' => (string) ($blockSnapshot['type'] ?? ''),
                'data' => is_array($blockSnapshot['data'] ?? null) ? $blockSnapshot['data'] : [],
                'sort_order' => (int) ($blockSnapshot['sort_order'] ?? 0),
                'is_visible' => (bool) ($blockSnapshot['is_visible'] ?? true),
            ]);

            if (! $block->exists) {
                $block->page()->associate($page);
            }

            $block->save();

            $keptIds[] = $block->id;
        }

        Block::query()
            ->where('page_id', $page->id)
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(fn (Block $block): ?bool => $block->delete());

        $revision->delete();
    }
}
