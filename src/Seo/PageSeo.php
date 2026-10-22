<?php

declare(strict_types=1);

namespace J87NL\Cms\Seo;

use Illuminate\Support\Str;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\HasJsonLd;
use J87NL\Cms\Enums\EntityType;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\SiteSettings;

class PageSeo
{
    public const int DESCRIPTION_LENGTH = 160;

    private const string FALLBACK_OPEN_GRAPH_LOCALE = 'en_US';

    /**
     * @var array<string, string>
     */
    private const array OPEN_GRAPH_LOCALES = [
        'cs' => 'cs_CZ',
        'da' => 'da_DK',
        'de' => 'de_DE',
        'el' => 'el_GR',
        'en' => 'en_US',
        'es' => 'es_ES',
        'fr' => 'fr_FR',
        'it' => 'it_IT',
        'ja' => 'ja_JP',
        'nb' => 'nb_NO',
        'nl' => 'nl_NL',
        'pl' => 'pl_PL',
        'pt' => 'pt_PT',
        'sv' => 'sv_SE',
        'uk' => 'uk_UA',
        'zh' => 'zh_CN',
    ];

    public function __construct(
        private readonly BlockRegistry $registry,
    ) {}

    public function forPage(Page $page, bool $preview = false): SeoData
    {
        $settings = SiteSettings::current();
        $blocks = $this->visibleBlocks($page);
        $canonical = $this->canonical($page);
        $description = $this->description($page, $blocks, $settings);
        $image = $this->image($page->og_image_id ?? $settings->og_image_id);
        $title = $this->title($page, $settings);
        $breadcrumbs = $this->breadcrumbs($page);

        $webPage = array_filter([
            '@type' => $page->schema_type ?: 'WebPage',
            '@id' => sprintf('%s#webpage', $canonical),
            'url' => $canonical,
            'name' => $page->meta_title ?: $page->title,
            'description' => $description === '' ? null : $description,
            'inLanguage' => $this->language(),
            'isPartOf' => [
                '@id' => $this->websiteId(),
            ],
            'about' => $page->is_home ? ['@id' => $this->entityId()] : null,
            'dateModified' => $page->updated_at?->toIso8601String(),
            'breadcrumb' => $breadcrumbs === [] ? null : ['@id' => sprintf('%s#breadcrumb', $canonical)],
        ], fn (mixed $value): bool => ! is_null($value));

        $graph = [
            $this->entity($settings),
            $this->website($settings),
            $webPage,
        ];

        if ($breadcrumbs !== []) {
            $graph[] = [
                '@type' => 'BreadcrumbList',
                '@id' => sprintf('%s#breadcrumb', $canonical),
                'itemListElement' => $breadcrumbs,
            ];
        }

        foreach ($this->blockJsonLd($blocks) as $node) {
            $graph[] = $node;
        }

        return new SeoData(
            title: $title,
            description: $description,
            canonical: $canonical,
            robots: $preview ? 'noindex, nofollow' : ($page->noindex ? 'noindex, follow' : null),
            siteName: $settings->siteName(),
            locale: $this->openGraphLocale(),
            image: $image,
            graph: $graph,
        );
    }

    public function title(Page $page, ?SiteSettings $settings = null): string
    {
        $settings ??= SiteSettings::current();
        $metaTitle = trim((string) $page->meta_title);

        if ($metaTitle !== '') {
            return $metaTitle;
        }

        return $settings->title($page->title);
    }

    /**
     * @param  list<Block>|null  $blocks
     */
    public function description(Page $page, ?array $blocks = null, ?SiteSettings $settings = null): string
    {
        $metaDescription = trim((string) $page->meta_description);

        if ($metaDescription !== '') {
            return $metaDescription;
        }

        foreach ($blocks ?? $this->visibleBlocks($page) as $block) {
            $text = trim($this->registry->get($block->type)?->toPlainText($block->data) ?? '');

            if ($text !== '') {
                return Str::limit($text, self::DESCRIPTION_LENGTH, '…', preserveWords: true);
            }
        }

        return trim((string) ($settings ?? SiteSettings::current())->default_meta_description);
    }

    public function canonical(Page $page): string
    {
        $canonical = trim((string) $page->canonical_url);

        return $canonical === '' ? url($page->url()) : $canonical;
    }

    public function websiteId(): string
    {
        return sprintf('%s/#website', rtrim(url('/'), '/'));
    }

    public function entityId(): string
    {
        return sprintf('%s/#entity', rtrim(url('/'), '/'));
    }

    /**
     * @return list<Block>
     */
    private function visibleBlocks(Page $page): array
    {
        return array_values($page->visibleBlocks()->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function entity(SiteSettings $settings): array
    {
        $image = $this->image($settings->entity_image_id);
        $isPerson = $settings->entity_type === EntityType::Person;

        return array_filter([
            '@type' => $settings->entity_type->value,
            '@id' => $this->entityId(),
            'name' => $settings->entityName(),
            'url' => url('/'),
            'jobTitle' => $isPerson ? (trim((string) $settings->entity_job_title) ?: null) : null,
            'email' => trim((string) $settings->entity_email) ?: null,
            $isPerson ? 'image' : 'logo' => $image['url'] ?? null,
            'sameAs' => $settings->sameAs() === [] ? null : $settings->sameAs(),
        ], fn (mixed $value): bool => ! is_null($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function website(SiteSettings $settings): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'url' => url('/'),
            'name' => $settings->siteName(),
            'inLanguage' => $this->language(),
            'publisher' => [
                '@id' => $this->entityId(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function breadcrumbs(Page $page): array
    {
        $segments = explode('/', $page->path);

        if (count($segments) < 2 || $page->is_home) {
            return [];
        }

        $paths = [];

        for ($length = 1; $length <= count($segments); $length++) {
            $paths[] = implode('/', array_slice($segments, 0, $length));
        }

        $pages = Page::query()
            ->published()
            ->whereIn('path', $paths)
            ->get()
            ->keyBy('path');

        $items = [];

        $home = Page::query()
            ->published()
            ->where('is_home', true)
            ->first();

        if (! is_null($home)) {
            $items[] = $this->crumb(count($items) + 1, $home->menuLabel(), url('/'));
        }

        foreach ($paths as $path) {
            $crumb = $pages->get($path);

            if (is_null($crumb) || $crumb->is_home) {
                continue;
            }

            $items[] = $this->crumb(count($items) + 1, $crumb->menuLabel(), url($crumb->url()));
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function crumb(int $position, string $name, string $url): array
    {
        return [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $name,
            'item' => $url,
        ];
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<array<string, mixed>>
     */
    private function blockJsonLd(array $blocks): array
    {
        $nodes = [];

        foreach ($blocks as $block) {
            $type = $this->registry->get($block->type);

            if (! $type instanceof HasJsonLd) {
                continue;
            }

            foreach ($type->jsonLd($block->data) as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string}|null
     */
    private function image(?int $id): ?array
    {
        if (is_null($id)) {
            return null;
        }

        $asset = MediaAsset::query()
            ->with('media')
            ->find($id);

        $source = $asset?->largestSource();

        if (is_null($asset) || is_null($source)) {
            return null;
        }

        return [
            ...$source,
            'url' => url($source['url']),
            'alt' => $asset->alt,
        ];
    }

    private function language(): string
    {
        return str_replace('_', '-', (string) config('app.locale'));
    }

    private function openGraphLocale(): string
    {
        $locale = str_replace('-', '_', trim((string) config('app.locale')));

        if (preg_match('/^([a-z]{2,3})_([a-z]{2})$/i', $locale, $parts) === 1) {
            return sprintf('%s_%s', Str::lower($parts[1]), Str::upper($parts[2]));
        }

        $language = Str::lower($locale);

        return self::OPEN_GRAPH_LOCALES[$language] ?? self::FALLBACK_OPEN_GRAPH_LOCALE;
    }
}
