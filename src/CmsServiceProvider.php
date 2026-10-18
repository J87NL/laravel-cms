<?php

declare(strict_types=1);

namespace J87NL\Cms;

use Illuminate\Support\ServiceProvider;

class CmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cms.php', 'cms');

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cms');

        $this->publishes([
            __DIR__.'/../config/cms.php' => config_path('cms.php'),
        ], 'cms-config');
    }

    private function registerPermissions(): void
    {
        $key = (string) config('cms.permissions_config_key');
        $registered = config($key, []);

        foreach (CmsPermission::names() as $permission) {
            if (in_array($permission, $registered, true)) {
                continue;
            }

            config()->push($key, $permission);
        }
    }
}
