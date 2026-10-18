<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Rendering\PageResponse;

class PreviewController extends Controller
{
    public const string ROUTE = 'cms.preview';

    public function show(string $page, PageResponse $pageResponse): Response
    {
        return $pageResponse->make(Page::query()->findOrFail((int) $page), preview: true);
    }

    public static function url(Page $page): string
    {
        return URL::temporarySignedRoute(self::ROUTE, now()->addMinutes((int) config('cms.preview.minutes', 60)), [
            'page' => $page->id,
        ]);
    }
}
