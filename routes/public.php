<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use J87NL\Cms\Http\Controllers\PublicPageController;

Route::middleware(config('cms.routes.middleware', []))->group(function (): void {
    Route::get('/', [PublicPageController::class, 'home'])->name((string) config('cms.routes.home_name', 'cms.home'));

    Route::fallback([PublicPageController::class, 'show'])->name('cms.page');
});
