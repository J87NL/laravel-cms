<?php

declare(strict_types=1);

namespace J87NL\Cms\Findability;

use J87NL\Cms\Models\SiteSettings;
use J87NL\Cms\Seo\PageSeo;

class LlmsTxt
{
    public function __construct(
        private readonly IndexablePages $pages,
        private readonly PageSeo $seo,
    ) {}

    public function build(): string
    {
        $settings = SiteSettings::current();

        $lines = [sprintf('# %s', $this->line($settings->siteName()))];

        $intro = trim((string) $settings->llms_intro) ?: trim((string) $settings->default_meta_description);

        if ($intro !== '') {
            $lines[] = '';
            $lines[] = sprintf('> %s', $this->line($intro));
        }

        $lines[] = '';
        $lines[] = sprintf('## %s', __('cms::public.llms.pages'));
        $lines[] = '';

        foreach ($this->pages->all() as $page) {
            $description = $this->line($this->seo->description($page, settings: $settings));
            $link = sprintf('- [%s](%s)', $this->line($page->title), url($page->url()));

            $lines[] = $description === '' ? $link : sprintf('%s: %s', $link, $description);
        }

        return implode("\n", $lines)."\n";
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']'], ['(', ')'], $text)));
    }
}
