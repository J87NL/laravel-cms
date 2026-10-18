<?php

declare(strict_types=1);

namespace J87NL\Cms\Tests;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use J87NL\Cms\CmsServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            CmsServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('media-library.queue_connection_name', 'sync');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $mediaLibrary = (string) InstalledVersions::getInstallPath('spatie/laravel-medialibrary');

        $migration = include sprintf('%s/database/migrations/create_media_table.php.stub', $mediaLibrary);

        $migration->up();
    }
}
