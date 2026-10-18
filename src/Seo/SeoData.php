<?php

declare(strict_types=1);

namespace J87NL\Cms\Seo;

final readonly class SeoData
{
    /**
     * @param  array{url: string, width: int, height: int, alt: string}|null  $image
     * @param  list<array<string, mixed>>  $graph
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public ?string $robots,
        public string $siteName,
        public string $locale,
        public ?array $image,
        public array $graph,
    ) {}

    public function jsonLd(): string
    {
        return (string) json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $this->graph,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
