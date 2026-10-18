<?php

declare(strict_types=1);

namespace J87NL\Cms\Redirects;

use J87NL\Cms\Actions\SaveRedirect;
use J87NL\Cms\Models\Page;

class RecordPathRedirects
{
    public function __construct(
        private readonly SaveRedirect $saveRedirect,
    ) {}

    public function updated(Page $page): void
    {
        if (! $page->wasChanged('path')) {
            return;
        }

        $this->saveRedirect->forPathChange((string) $page->getOriginal('path'), $page->path);
    }
}
