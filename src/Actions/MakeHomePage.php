<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\HomePageLock;
use J87NL\Cms\Pages\PageVisibility;

class MakeHomePage
{
    public function __construct(
        private readonly HomePageLock $homePageLock,
        private readonly PageVisibility $visibility,
    ) {}

    public function handle(Page $page): void
    {
        $this->homePageLock->run(function () use ($page): void {
            DB::transaction(function () use ($page): void {
                $locked = Page::query()
                    ->lockForUpdate()
                    ->findOrFail($page->id);

                if ($locked->is_home) {
                    return;
                }

                $reason = $this->blockedReason($locked);

                if (! is_null($reason)) {
                    throw ValidationException::withMessages([
                        'page' => $reason,
                    ]);
                }

                Page::query()
                    ->where('is_home', true)
                    ->update([
                        'is_home' => false,
                    ]);

                $locked->is_home = true;
                $locked->save();

                $page->setRawAttributes($locked->getAttributes(), true);
            });
        });
    }

    public function blockedReason(Page $page): ?string
    {
        if ($this->visibility->isPublic($page)) {
            return null;
        }

        return __('cms::validation.home_not_public');
    }
}
