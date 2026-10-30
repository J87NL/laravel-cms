<?php

declare(strict_types=1);

namespace J87NL\Cms\Findability;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Pages\PageVisibility;

class IndexablePages
{
    public function __construct(
        private readonly PageVisibility $visibility,
    ) {}

    /**
     * @return list<Page>
     */
    public function all(): array
    {
        $pages = Page::query()
            ->published()
            ->where('noindex', false)
            ->orderByDesc('is_home')
            ->orderBy('path')
            ->get();

        $hiddenAncestors = $this->visibility->hiddenAncestorPaths(array_values($pages->pluck('path')->map(fn (mixed $path): string => (string) $path)->all()));

        return array_values($pages
            ->reject(fn (Page $page): bool => $this->visibility->isHiddenBy($page->path, $hiddenAncestors))
            ->reject(fn (Page $page): bool => $this->pointsElsewhere($page))
            ->all());
    }

    /**
     * @param  list<Page>  $pages
     * @return array<int, CarbonInterface>
     */
    public function lastModified(array $pages): array
    {
        $ids = array_map(fn (Page $page): int => $page->id, $pages);

        $blockDates = Block::query()
            ->whereIn('page_id', $ids)
            ->groupBy('page_id')
            ->selectRaw('page_id, max(updated_at) as updated_at')
            ->pluck('updated_at', 'page_id');

        $dates = [];

        foreach ($pages as $page) {
            $candidates = array_filter([
                $page->updated_at,
                is_null($blockDates->get($page->id)) ? null : Carbon::parse((string) $blockDates->get($page->id)),
            ]);

            if ($candidates === []) {
                continue;
            }

            $dates[$page->id] = max($candidates);
        }

        return $dates;
    }

    private function pointsElsewhere(Page $page): bool
    {
        $canonical = trim((string) $page->canonical_url);

        return $canonical !== '' && rtrim($canonical, '/') !== rtrim(url($page->url()), '/');
    }
}
