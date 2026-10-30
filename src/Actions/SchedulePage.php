<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Page;

class SchedulePage
{
    public function handle(Page $page, ?CarbonInterface $publishAt, ?CarbonInterface $unpublishAt): void
    {
        if (! is_null($publishAt) && $page->isPublished()) {
            $publishAt = null;
        }

        if (! is_null($publishAt) && $publishAt->isPast()) {
            throw ValidationException::withMessages([
                'publish_at' => __('cms::validation.schedule_in_past'),
            ]);
        }

        if (! is_null($unpublishAt)) {
            $this->assertCanUnpublish($page, $publishAt, $unpublishAt);
        }

        $page->publish_at = $publishAt;
        $page->unpublish_at = $unpublishAt;
        $page->schedule_error = null;
        $page->save();
    }

    private function assertCanUnpublish(Page $page, ?CarbonInterface $publishAt, CarbonInterface $unpublishAt): void
    {
        if ($unpublishAt->isPast()) {
            throw ValidationException::withMessages([
                'unpublish_at' => __('cms::validation.schedule_in_past'),
            ]);
        }

        if (! is_null($publishAt) && $unpublishAt->lessThanOrEqualTo($publishAt)) {
            throw ValidationException::withMessages([
                'unpublish_at' => __('cms::validation.unpublish_before_publish'),
            ]);
        }

        $reason = ChangePageStatus::unpublishBlockedReason($page);

        if (! is_null($reason)) {
            throw ValidationException::withMessages([
                'unpublish_at' => $reason,
            ]);
        }
    }
}
