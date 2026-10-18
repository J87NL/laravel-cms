<?php

declare(strict_types=1);

namespace J87NL\Cms;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\Types\CallToActionBlock;
use J87NL\Cms\Blocks\Types\DatesBlock;
use J87NL\Cms\Blocks\Types\EmbedBlock;
use J87NL\Cms\Blocks\Types\GalleryBlock;
use J87NL\Cms\Blocks\Types\ImageBlock;
use J87NL\Cms\Blocks\Types\RichTextBlock;
use J87NL\Cms\Blocks\Types\TextWithImageBlock;
use J87NL\Cms\Html\RichTextSanitizer;

class CmsServiceProvider extends ServiceProvider
{
    /**
     * @var list<class-string<Blocks\BlockType>>
     */
    public const array CORE_BLOCK_TYPES = [
        RichTextBlock::class,
        TextWithImageBlock::class,
        ImageBlock::class,
        GalleryBlock::class,
        CallToActionBlock::class,
        EmbedBlock::class,
        DatesBlock::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cms.php', 'cms');

        $this->app->singleton(RichTextSanitizer::class);
        $this->app->singleton(BlockRegistry::class, function (Application $app): BlockRegistry {
            $registry = new BlockRegistry($app);

            foreach (self::CORE_BLOCK_TYPES as $type) {
                $registry->register($type);
            }

            return $registry;
        });
        $this->app->singleton(Cms::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cms');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Blade::componentNamespace('J87NL\\Cms\\View\\Components', 'cms');

        if (config('cms.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/public.php');
        }

        $this->publishes([
            __DIR__.'/../config/cms.php' => config_path('cms.php'),
        ], 'cms-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/cms'),
        ], 'cms-views');
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
