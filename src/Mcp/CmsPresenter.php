<?php

declare(strict_types=1);

namespace J87NL\Cms\Mcp;

use Illuminate\Database\Eloquent\Collection;
use J87NL\Cms\Models\Block;
use J87NL\Cms\Models\MediaAsset;
use J87NL\Cms\Models\MenuItem;
use J87NL\Cms\Models\Page;
use J87NL\Cms\Models\Redirect;
use J87NL\Cms\Models\Revision;
use J87NL\Cms\Pages\PageVisibility;
use J87NL\Cms\Pages\PendingRevisionEditor;

class CmsPresenter
{
    public function __construct(
        private readonly PageVisibility $visibility,
        private readonly PendingRevisionEditor $pending,
    ) {}

    /**
     * @param  Collection<int, Page>  $pages
     * @param  list<int>|null  $pendingPageIds
     * @return list<array<string, mixed>>
     */
    public function pageTree(Collection $pages, ?int $parentId = null, bool $ancestorsPublic = true, ?array $pendingPageIds = null): array
    {
        $pendingPageIds ??= $this->pendingPageIds();
        $nodes = [];

        foreach ($pages->where('parent_id', $parentId) as $page) {
            $isPublic = $ancestorsPublic && $page->isPublished();

            $nodes[] = [
                ...$this->pageSummary($page, $isPublic, in_array($page->id, $pendingPageIds, true)),
                'children' => $this->pageTree($pages, $page->id, $isPublic, $pendingPageIds),
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function pageSummary(Page $page, ?bool $isPublic = null, ?bool $hasPendingRevision = null): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'path' => sprintf('/%s', $page->path),
            'url' => $page->url(),
            'status' => $page->status->value,
            'is_home' => $page->is_home,
            'is_public' => $isPublic ?? $this->visibility->isPublic($page),
            'writable' => is_null($page->publish_at),
            'has_pending_revision' => $hasPendingRevision ?? ! is_null($this->pending->find($page)),
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
            'pending_revision' => $this->pendingRevisionOf($page),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingRevisionOf(Page $page): ?array
    {
        $revision = $this->pending->find($page);

        return is_null($revision) ? null : $this->pendingRevision($revision);
    }

    /**
     * @return array<string, mixed>
     */
    public function pendingRevision(Revision $revision): array
    {
        $blocks = $revision->snapshot['blocks'];

        usort($blocks, fn (array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        return [
            'id' => $revision->id,
            'created_by' => $revision->created_by,
            'created_at' => $revision->created_at?->toIso8601String(),
            'updated_at' => $revision->updated_at?->toIso8601String(),
            'page' => $revision->snapshot['page'],
            'blocks' => array_map(fn (array $block): array => $this->pendingBlock($block), $blocks),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function pendingBlock(array $block): array
    {
        return [
            'id' => (int) ($block['id'] ?? 0),
            'type' => (string) ($block['type'] ?? ''),
            'position' => (int) ($block['sort_order'] ?? 0),
            'is_visible' => (bool) ($block['is_visible'] ?? true),
            'data' => is_array($block['data'] ?? null) ? $block['data'] : [],
        ];
    }

    /**
     * @return list<int>
     */
    private function pendingPageIds(): array
    {
        return array_values(array_map('intval', Revision::query()
            ->pending()
            ->pluck('page_id')
            ->all()));
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
