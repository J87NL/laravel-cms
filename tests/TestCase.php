<?php

declare(strict_types=1);

namespace J87NL\Cms\Tests;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use J87NL\Cms\CmsServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use TranquilTools\FormBuilder\FormBuilderServiceProvider;

use function Orchestra\Testbench\default_migration_path;

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
            McpServiceProvider::class,
            FormBuilderServiceProvider::class,
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

        $users = include sprintf('%s/0001_01_01_000000_testbench_create_users_table.php', default_migration_path());

        $users->up();
    }
}
