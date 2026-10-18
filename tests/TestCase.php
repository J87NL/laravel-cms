<?php

declare(strict_types=1);

namespace J87NL\Cms\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use J87NL\Cms\CmsServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
            CmsServiceProvider::class,
        ];
    }
}
