<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use J87NL\Cms\CmsPermission;
use J87NL\Cms\Http\Controllers\ContactFormController;
use J87NL\Cms\Http\Controllers\FindabilityController;
use J87NL\Cms\Http\Controllers\PreviewController;
use J87NL\Cms\Http\Controllers\PublicPageController;
use J87NL\Cms\Http\Middleware\AddSecurityHeaders;
use J87NL\Cms\Http\Middleware\CachePublicResponses;

Route::middleware([
    ...config('cms.routes.middleware', []),
    AddSecurityHeaders::class,
])->group(function (): void {
    Route::get('cms/preview/{page}', [PreviewController::class, 'show'])
        ->middleware('signed')
        ->name(PreviewController::ROUTE);

    Route::get('cms/preview/{page}/pending/{revision}', [PreviewController::class, 'pending'])
        ->middleware([
            ...config('cms.preview.pending_middleware', [
                'web',
                'auth',
            ]),
            sprintf('can:%s', CmsPermission::PagesEdit->value),
            'signed',
        ])
        ->name(PreviewController::PENDING_ROUTE);

    Route::post('cms/contact/{block}', [ContactFormController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('cms.contact');

    Route::middleware(CachePublicResponses::class)->group(function (): void {
        Route::get('sitemap.xml', [FindabilityController::class, 'sitemap'])->name('cms.sitemap');
        Route::get('robots.txt', [FindabilityController::class, 'robots'])->name('cms.robots');
        Route::get('llms.txt', [FindabilityController::class, 'llms'])->name('cms.llms');

        Route::get('/', [PublicPageController::class, 'home'])->name((string) config('cms.routes.home_name', 'cms.home'));

        Route::fallback([PublicPageController::class, 'show'])->name('cms.page');
    });
});
