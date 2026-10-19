<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Pages\PageVisibility;

class CmsPresenter
{
    public function __construct(
        private readonly PageVisibility $visibility,
    ) {}

    /**
     * @param  Collection<int, Page>  $pages
     * @return list<array<string, mixed>>
     */
    public function pageTree(Collection $pages, ?int $parentId = null, bool $ancestorsPublic = true): array
    {
        $nodes = [];

        foreach ($pages->where('parent_id', $parentId) as $page) {
            $isPublic = $ancestorsPublic && $page->isPublished();

            $nodes[] = [
                ...$this->pageSummary($page, $isPublic),
                'children' => $this->pageTree($pages, $page->id, $isPublic),
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function pageSummary(Page $page, ?bool $isPublic = null): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'path' => sprintf('/%s', $page->path),
            'url' => $page->url(),
            'status' => $page->status->value,
            'is_home' => $page->is_home,
            'is_public' => $isPublic ?? $this->visibility->isPublic($page),
            'writable' => ! $page->isPublished() && is_null($page->publish_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(Page $page): array
    {
        $blocks = Block::query()
            ->where('page_id', $page->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            ...$this->pageSummary($page),
            'parent_id' => $page->parent_id,
            'slug' => $page->slug,
            'menu_title' => $page->menu_title,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'og_image_id' => $page->og_image_id,
            'canonical_url' => $page->canonical_url,
            'noindex' => $page->noindex,
            'schema_type' => $page->schema_type,
            'publish_at' => $page->publish_at?->toIso8601String(),
            'unpublish_at' => $page->unpublish_at?->toIso8601String(),
            'blocks' => array_values($blocks
                ->map(fn (Block $block): array => $this->block($block))
                ->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function block(Block $block): array
    {
        return [
            'id' => $block->id,
            'type' => $block->type,
            'position' => $block->sort_order,
            'is_visible' => $block->is_visible,
            'data' => $block->data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function menuItem(MenuItem $item, bool $isVisible): array
    {
        return [
            'id' => $item->id,
            'label' => $item->displayLabel(),
            'custom_label' => $item->label,
            'page_id' => $item->page_id,
            'url' => $item->url,
            'href' => $item->href(),
            'parent_id' => $item->parent_id,
            'position' => $item->sort_order,
            'is_visible' => $isVisible,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redirect(Redirect $redirect): array
    {
        return [
            'id' => $redirect->id,
            'from_path' => $redirect->fromUrl(),
            'to' => $redirect->to,
            'status_code' => $redirect->status_code,
            'source' => $redirect->source->value,
            'hits' => $redirect->hits,
            'last_hit_at' => $redirect->last_hit_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(MediaAsset $asset, int $usageCount): array
    {
        return [
            'id' => $asset->id,
            'alt' => $asset->alt,
            'caption' => $asset->caption,
            'url' => $asset->largestSource()['url'] ?? $asset->originalUrl(),
            'thumbnail_url' => $asset->thumbnailUrl(),
            'width' => $asset->width(),
            'height' => $asset->height(),
            'in_use' => $usageCount > 0,
            'usage_count' => $usageCount,
        ];
    }
}
