<?php

declare(strict_types=1);

namespace J87NL\Cms\Sitemap;

use XMLWriter;

class SitemapGenerator
{
    public function __construct(
        private readonly SitemapRegistry $registry,
    ) {}

    public function build(): string
    {
        $writer = new XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        $seen = [];

        foreach ($this->registry->contributors() as $contributor) {
            foreach ($contributor->urls() as $url) {
                if (array_key_exists($url->loc, $seen)) {
                    continue;
                }

                $seen[$url->loc] = true;

                $writer->startElement('url');
                $writer->writeElement('loc', $url->loc);

                if (! is_null($url->lastmod)) {
                    $writer->writeElement('lastmod', $url->lastmod->toAtomString());
                }

                $writer->endElement();
            }
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }
}
