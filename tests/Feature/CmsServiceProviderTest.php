<?php

declare(strict_types=1);

use J87NL\Cms\CmsPermission;
use J87NL\Cms\CmsServiceProvider;

test('the provider registers every cms permission once', function (): void {
    $this->app->register(CmsServiceProvider::class, force: true);

    $permissions = config('j87nl.permissions');

    expect($permissions)
        ->toContain(...CmsPermission::names())
        ->and(array_count_values($permissions)[CmsPermission::PagesEdit->value])->toBe(1);
});

test('the provider registers its permissions under the configured key', function (): void {
    config()->set('cms.permissions_config_key', 'other.permissions');

    $this->app->register(CmsServiceProvider::class, force: true);

    expect(config('other.permissions'))->toContain(CmsPermission::PagesEdit->value);
});
