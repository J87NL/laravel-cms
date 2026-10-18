<?php

declare(strict_types=1);

namespace J87NL\Cms\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Rendering\PageResponse;
use J87NL\Cms\View\Components\Menu;

class PublicPageController extends Controller
{
    public function __construct(
        private readonly PageVisibility $visibility,
        private readonly PageResponse $pageResponse,
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
            return $this->redirectFor($request, $path) ?? $this->notFound($request);
        }

        if ($page->is_home) {
            return $this->redirect($request, '/');
        }

        if ($requestedPath !== $path) {
            return $this->redirect($request, sprintf('/%s', $path));
        }

        return $this->page($page);
    }

    private function redirectFor(Request $request, string $path): ?RedirectResponse
    {
        $redirect = Redirect::query()
            ->where('from_path', $path)
            ->first();

        if (is_null($redirect)) {
            return null;
        }

        $redirect->recordHit();

        $query = $request->getQueryString();
        $target = $redirect->to;

        if (! is_null($query) && ! str_contains($target, '?') && ! str_contains($target, '#')) {
            $target = sprintf('%s?%s', $target, $query);
        }

        return redirect($target, $redirect->status_code);
    }

    private function redirect(Request $request, string $path): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect(is_null($query) ? $path : sprintf('%s?%s', $path, $query), 301);
    }

    private function page(Page $page): Response
    {
        return $this->pageResponse->make($page);
    }

    private function notFound(Request $request): Response
    {
        $request->attributes->set(Menu::HIDDEN_ATTRIBUTE, true);

        return response()->view('cms::not-found', [], 404);
    }
}
