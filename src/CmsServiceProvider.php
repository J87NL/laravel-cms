<?php

declare(strict_types=1);

namespace J87NL\Cms;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\Types\CallToActionBlock;
use J87NL\Cms\Blocks\Types\ContactFormBlock;
use J87NL\Cms\Blocks\Types\DatesBlock;
use J87NL\Cms\Blocks\Types\EmbedBlock;
use J87NL\Cms\Blocks\Types\GalleryBlock;
use J87NL\Cms\Blocks\Types\ImageBlock;
use J87NL\Cms\Blocks\Types\RichTextBlock;
use J87NL\Cms\Blocks\Types\TextWithImageBlock;
use J87NL\Cms\Cache\FlushPageCache;
use J87NL\Cms\Console\PublishScheduledPages;
use J87NL\Cms\Html\RichTextSanitizer;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Menu;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\SiteSettings;
use J87NL\Cms\Redirects\RecordPathRedirects;
use J87NL\Cms\Sitemap\PageSitemapContributor;
use J87NL\Cms\Sitemap\SitemapRegistry;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        ContactFormBlock::class,
    ];

    /**
     * @var list<class-string<Model>>
     */
    private const array CACHED_CONTENT_MODELS = [
        Page::class,
        Block::class,
        Menu::class,
        MenuItem::class,
        SiteSettings::class,
        MediaAsset::class,
        Media::class,
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
        $this->app->singleton(SitemapRegistry::class, fn (Application $app): SitemapRegistry => (new SitemapRegistry($app))->contribute(PageSitemapContributor::class));
        $this->app->singleton(Cms::class);

        $this->registerPermissions();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cms');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Blade::componentNamespace('J87NL\\Cms\\View\\Components', 'cms');

        Page::observe(RecordPathRedirects::class);

        foreach (self::CACHED_CONTENT_MODELS as $model) {
            $model::observe(FlushPageCache::class);
        }

        RateLimiter::for('cms-contact', fn (Request $request): Limit => Limit::perHour((int) config('cms.contact.per_hour', 5))->by((string) $request->ip()));

        if (config('cms.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/public.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PublishScheduledPages::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('cms.scheduling.enabled', true)) {
                return;
            }

            $schedule->command(PublishScheduledPages::class)
                ->everyMinute()
                ->withoutOverlapping();
        });

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
