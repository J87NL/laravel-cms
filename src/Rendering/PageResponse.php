<?php

declare(strict_types=1);

namespace J87NL\Cms\Rendering;

use Illuminate\Http\Response;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Seo\PageSeo;

class PageResponse
{
    public function __construct(
        private readonly PageSeo $seo,
    ) {}

    public function make(Page $page, bool $preview = false): Response
    {
        $response = response()->view('cms::page', [
            'page' => $page,
            'title' => $this->seo->title($page),
            'preview' => $preview,
        ]);

        if ($preview) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
