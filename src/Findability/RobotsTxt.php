<?php

declare(strict_types=1);

namespace J87NL\Cms\Findability;

use J87NL\Cms\Models\SiteSettings;

class RobotsTxt
{
    public function build(): string
    {
        $lines = ['User-agent: *'];

        $disallowed = config('cms.robots.disallow', []);

        foreach (is_array($disallowed) ? $disallowed : [] as $path) {
            $lines[] = sprintf('Disallow: %s', (string) $path);
        }

        $extra = SiteSettings::current()->robotsLines();

        if ($extra !== []) {
            $lines[] = '';

            foreach ($extra as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = sprintf('Sitemap: %s', url('/sitemap.xml'));

        return implode("\n", $lines)."\n";
    }
}
