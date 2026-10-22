<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PendingRevisionApplier;
use J87NL\Cms\Pages\PendingRevisionEditor;
use J87NL\Cms\Rendering\PageResponse;

class PreviewController extends Controller
{
    public const string ROUTE = 'cms.preview';

    public const string PENDING_ROUTE = 'cms.preview.pending';

    public function show(string $page, PageResponse $pageResponse): Response
    {
        $preview = Page::query()
            ->findOrFail((int) $page);

        return $pageResponse->make($preview, preview: true);
    }

    public function pending(string $page, PageResponse $pageResponse, PendingRevisionEditor $pending, PendingRevisionApplier $applier): Response
    {
        $preview = Page::query()
            ->findOrFail((int) $page);

        $revision = $pending->find($preview);

        if (is_null($revision)) {
            return $pageResponse->make($preview, preview: true);
        }

        return $pageResponse->make($applier->plan($preview, $revision)->page($preview), preview: true);
    }

    public static function url(Page $page): string
    {
        return URL::temporarySignedRoute(self::ROUTE, self::expiresAt(), [
            'page' => $page->id,
        ]);
    }

    public static function pendingUrl(Page $page): string
    {
        return URL::temporarySignedRoute(self::PENDING_ROUTE, self::expiresAt(), [
            'page' => $page->id,
        ]);
    }

    private static function expiresAt(): CarbonInterface
    {
        return now()->addMinutes((int) config('cms.preview.minutes', 60));
    }
}
