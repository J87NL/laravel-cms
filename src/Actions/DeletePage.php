<?php

declare(strict_types=1);

namespace J87NL\Cms\Actions;

use Illuminate\Validation\ValidationException;
use J87NL\Cms\Models\Page;

class DeletePage
{
    public function handle(Page $page): void
    {
        $reason = self::blockedReason($page);

        if (! is_null($reason)) {
            throw ValidationException::withMessages([
                'page' => $reason,
            ]);
        }

        $page->delete();
    }

    public static function blockedReason(Page $page): ?string
    {
        if ($page->is_home) {
            return __('cms::validation.delete_home');
        }

        $hasChildren = Page::query()
            ->where('parent_id', $page->id)
            ->exists();

        if ($hasChildren) {
            return __('cms::validation.delete_has_children');
        }

        return null;
    }
}
