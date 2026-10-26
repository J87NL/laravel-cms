<?php

declare(strict_types=1);

use J87NL\Cms\CmsServiceProvider;

/**
 * @param  array<string, mixed>  $host
 */
function registerWithHostConfig(array $host): void
{
    config()->set('cms', $host);

    (new CmsServiceProvider(app()))->register();
}

test('a published config without the newer nested keys gets them from the package', function (): void {
    putenv('CMS_CONTACT_RSPAMD_URL=http://127.0.0.1:11333/checkv2');

    try {
        registerWithHostConfig([
            'contact' => [
                'recipient' => 'info@example.test',
                'per_hour' => 10,
            ],
        ]);
    } finally {
        putenv('CMS_CONTACT_RSPAMD_URL');
    }

    expect(config('cms.contact.rspamd.url'))->toBe('http://127.0.0.1:11333/checkv2')
        ->and(config('cms.contact.rspamd.timeout'))->toBe(2)
        ->and(config('cms.contact.rspamd.threshold'))->toBe(6)
        ->and(config('cms.contact.per_minute'))->toBe(3)
        ->and(config('cms.contact.max_links'))->toBe(2)
        ->and(config('cms.routes.enabled'))->toBeTrue();
});

test('values in the host config win over the package defaults, also nested ones', function (): void {
    registerWithHostConfig([
        'contact' => [
            'per_hour' => 10,
            'max_links' => null,
            'rspamd' => [
                'threshold' => 12,
            ],
        ],
    ]);

    expect(config('cms.contact.per_hour'))->toBe(10)
        ->and(config('cms.contact.max_links'))->toBeNull()
        ->and(config('cms.contact.rspamd.threshold'))->toBe(12)
        ->and(config('cms.contact.rspamd.timeout'))->toBe(2);
});

test('a list in the host config replaces the package list as a whole', function (): void {
    registerWithHostConfig([
        'contact' => [
            'blocked_keywords' => ['casino'],
        ],
        'robots' => [
            'disallow' => ['/private'],
        ],
        'security_headers' => [
            'csp' => [],
        ],
    ]);

    expect(config('cms.contact.blocked_keywords'))->toBe(['casino'])
        ->and(config('cms.robots.disallow'))->toBe(['/private'])
        ->and(config('cms.security_headers.csp'))->toBe([]);
});

test('an empty host array for a keyed section keeps the package defaults', function (): void {
    registerWithHostConfig([
        'contact' => [
            'rspamd' => [],
        ],
    ]);

    expect(config('cms.contact.rspamd.timeout'))->toBe(2);
});
