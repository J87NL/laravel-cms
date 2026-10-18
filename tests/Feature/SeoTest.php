<?php

declare(strict_types=1);

use J87NL\Cms\Actions\SaveSiteSettings;

/**
 * @return array<string, mixed>
 */
function jsonLdGraph(string $html): array
{
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return json_decode($matches[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $jsonLd
 * @return list<array<string, mixed>>
 */
function nodesOfType(array $jsonLd, string $type): array
{
    return array_values(array_filter($jsonLd['@graph'] ?? [], fn (array $node): bool => ($node['@type'] ?? null) === $type));
}

beforeEach(function (): void {
    app(SaveSiteSettings::class)->handle([
        'site_name' => 'Johan Montenij',
        'title_pattern' => ':title | Johan',
        'default_meta_description' => 'Default description',
        'entity_type' => 'Person',
        'entity_name' => 'Johan Montenij',
        'entity_job_title' => 'Developer',
        'entity_same_as' => "https://github.com/J87NL\n\nhttps://www.linkedin.com/in/example",
    ]);
});

test('pages get title, description, canonical and social tags', function (): void {
    $page = makePage([
        'title' => 'About me',
        'slug' => 'about',
    ]);

    addBlock($page, 'rich_text', [
        'html' => '<p>I build <strong>calm</strong> software.</p>',
    ]);

    $this->get('/about')
        ->assertOk()
        ->assertSee('<title>About me | Johan</title>', false)
        ->assertSee('<meta name="description" content="I build calm software.">', false)
        ->assertSee(sprintf('<link rel="canonical" href="%s">', url('/about')), false)
        ->assertSee('<meta property="og:title" content="About me | Johan">', false)
        ->assertSee('<meta name="twitter:card" content="summary">', false)
        ->assertDontSee('name="robots"', false);
});

test('meta fields override the generated values', function (): void {
    makePage([
        'title' => 'About me',
        'slug' => 'about',
        'meta_title' => 'Custom title',
        'meta_description' => 'Custom description',
        'canonical_url' => 'https://example.org/about',
        'noindex' => true,
    ]);

    $this->get('/about')
        ->assertSee('<title>Custom title</title>', false)
        ->assertSee('<meta name="description" content="Custom description">', false)
        ->assertSee('<link rel="canonical" href="https://example.org/about">', false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
});

test('a page without text falls back to the default description', function (): void {
    makePage([
        'title' => 'Empty',
        'slug' => 'empty',
    ]);

    $this->get('/empty')->assertSee('<meta name="description" content="Default description">', false);
});

test('json-ld describes the person, the website and the page', function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);

    $jsonLd = jsonLdGraph((string) $this->get('/')->getContent());

    expect($jsonLd['@context'])->toBe('https://schema.org')
        ->and(nodesOfType($jsonLd, 'Person')[0])->toMatchArray([
            'name' => 'Johan Montenij',
            'jobTitle' => 'Developer',
            'sameAs' => [
                'https://github.com/J87NL',
                'https://www.linkedin.com/in/example',
            ],
        ])
        ->and(nodesOfType($jsonLd, 'WebSite')[0]['name'])->toBe('Johan Montenij')
        ->and(nodesOfType($jsonLd, 'WebPage')[0]['about'])->toBe(['@id' => url('/').'/#entity'])
        ->and(nodesOfType($jsonLd, 'BreadcrumbList'))->toBe([]);
});

test('nested pages get a breadcrumb list', function (): void {
    makePage([
        'title' => 'Welcome',
        'slug' => 'welcome',
    ], home: true);
    $parent = makePage([
        'title' => 'Projects',
        'slug' => 'projects',
    ]);
    makePage([
        'title' => 'Site',
        'slug' => 'site',
        'parent_id' => $parent->id,
    ]);

    $breadcrumbs = nodesOfType(jsonLdGraph((string) $this->get('/projects/site')->getContent()), 'BreadcrumbList')[0];

    expect(array_column($breadcrumbs['itemListElement'], 'name'))->toBe(['Welcome', 'Projects', 'Site'])
        ->and(array_column($breadcrumbs['itemListElement'], 'item'))->toBe([url('/'), url('/projects'), url('/projects/site')]);
});

test('dates blocks emit events with their location', function (): void {
    $page = makePage([
        'title' => 'Market',
        'slug' => 'market',
    ]);

    addBlock($page, 'dates', [
        'title' => 'Dates',
        'location_name' => 'De Schuur',
        'location_address' => 'Dorpsstraat 1, Heenvliet',
        'items' => [
            [
                'date' => '2027-05-01',
                'title' => 'Spring market',
                'description' => 'Bring your own bag',
            ],
        ],
    ]);

    $event = nodesOfType(jsonLdGraph((string) $this->get('/market')->getContent()), 'Event')[0];

    expect($event)->toMatchArray([
        'name' => 'Spring market',
        'startDate' => '2027-05-01',
        'description' => 'Bring your own bag',
        'location' => [
            '@type' => 'Place',
            'name' => 'De Schuur',
            'address' => 'Dorpsstraat 1, Heenvliet',
        ],
    ]);
});

test('json-ld cannot break out of its script tag', function (): void {
    makePage([
        'title' => '</script><script>alert(1)</script>',
        'slug' => 'evil',
    ]);

    $html = (string) $this->get('/evil')->getContent();

    expect($html)->not->toContain('<script>alert(1)')
        ->and(jsonLdGraph($html)['@graph'])->not->toBeEmpty();
});

test('the not found page is not indexed', function (): void {
    $this->get('/missing')->assertSee('<meta name="robots" content="noindex">', false);
});
