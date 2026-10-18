<?php

declare(strict_types=1);

use J87NL\Cms\Actions\SaveSiteSettings;
use J87NL\Cms\Enums\PageStatus;
use J87NL\Cms\Facades\Cms;
use J87NL\Cms\Sitemap\SitemapContributor;
use J87NL\Cms\Sitemap\SitemapUrl;

beforeEach(function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
        'meta_description' => 'The front door',
    ], home: true);
    $about = makePage([
        'title' => 'About',
        'slug' => 'about',
    ]);
    addBlock($about, 'rich_text', [
        'html' => '<p>Who I am</p>',
    ]);
    makePage([
        'title' => 'Draft',
        'slug' => 'draft',
    ], PageStatus::Draft);
    makePage([
        'title' => 'Hidden',
        'slug' => 'hidden',
        'noindex' => true,
    ]);
    makePage([
        'title' => 'Elsewhere',
        'slug' => 'elsewhere',
        'canonical_url' => 'https://example.org/elsewhere',
    ]);
    $offline = makePage([
        'title' => 'Offline',
        'slug' => 'offline',
    ], PageStatus::Unpublished);
    makePage([
        'title' => 'Orphan',
        'slug' => 'orphan',
        'parent_id' => $offline->id,
    ]);
});

test('the sitemap lists only published, indexable pages', function (): void {
    $response = $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $xml = simplexml_load_string((string) $response->getContent());
    $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]') ?: []);

    expect($locations)->toBe([url('/'), url('/about')])
        ->and((string) $response->getContent())->toContain('<lastmod>');
});

test('apps add urls to the sitemap through a contributor', function (): void {
    Cms::sitemap()->contribute(new class implements SitemapContributor
    {
        public function urls(): iterable
        {
            yield new SitemapUrl('https://j87.nl/extra');
            yield new SitemapUrl(url('/about'));
        }
    });

    $content = (string) $this->get('/sitemap.xml')->getContent();

    expect(substr_count($content, '<loc>'))->toBe(3)
        ->and($content)->toContain('<loc>https://j87.nl/extra</loc>');
});

test('robots.txt blocks the admin, adds the settings lines and points at the sitemap', function (): void {
    app(SaveSiteSettings::class)->handle([
        'robots_extra' => "User-agent: GPTBot\nDisallow: /",
    ]);

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSeeInOrder([
            'User-agent: *',
            'Disallow: /admin',
            'User-agent: GPTBot',
            'Disallow: /',
            sprintf('Sitemap: %s', url('/sitemap.xml')),
        ], false);
});

test('llms.txt summarises the site and its indexable pages', function (): void {
    app(SaveSiteSettings::class)->handle([
        'site_name' => 'Johan Montenij',
        'llms_intro' => 'Developer in Heenvliet.',
    ]);

    $content = (string) $this->get('/llms.txt')->assertOk()->getContent();

    expect($content)->toStartWith("# Johan Montenij\n\n> Developer in Heenvliet.\n")
        ->toContain(sprintf('- [Welcome](%s): The front door', url('/')))
        ->toContain(sprintf('- [About](%s): Who I am', url('/about')))
        ->not->toContain('Draft')
        ->not->toContain('Hidden')
        ->not->toContain('Orphan')
        ->not->toContain('Elsewhere');
});
