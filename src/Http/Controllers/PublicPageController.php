<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\View\Components\Menu;

class PublicPageController extends Controller
{
    public function __construct(
        private readonly PageVisibility $visibility,
    ) {}

    public function home(Request $request): Response
    {
        $page = Page::query()
            ->published()
            ->where('is_home', true)
            ->first();

        if (! is_null($page) && $this->visibility->isPublic($page)) {
            return $this->page($page);
        }

        $fallback = config('cms.fallback_home_view');

        if (is_string($fallback) && $fallback !== '' && view()->exists($fallback)) {
            return response()->view($fallback);
        }

        return $this->notFound($request);
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $requestedPath = trim($request->path(), '/');
        $path = Str::lower($requestedPath);

        $page = Page::query()
            ->published()
            ->where('path', $path)
            ->first();

        if (is_null($page) || ! $this->visibility->isPublic($page)) {
            return $this->notFound($request);
        }

        if ($page->is_home) {
            return $this->redirect($request, '/');
        }

        if ($requestedPath !== $path) {
            return $this->redirect($request, sprintf('/%s', $path));
        }

        return $this->page($page);
    }

    private function redirect(Request $request, string $path): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect(is_null($query) ? $path : sprintf('%s?%s', $path, $query), 301);
    }

    private function page(Page $page): Response
    {
        return response()->view('cms::page', [
            'page' => $page,
        ]);
    }

    private function notFound(Request $request): Response
    {
        $request->attributes->set(Menu::HIDDEN_ATTRIBUTE, true);

        return response()->view('cms::not-found', [], 404);
    }
}
