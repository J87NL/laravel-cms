<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property int|null $page_id
 * @property string|null $url
 * @property string|null $label
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Menu $menu
 * @property-read MenuItem|null $parent
 * @property-read Page|null $page
 */
class MenuItem extends Model
{
    protected $table = 'cms_menu_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'page_id',
        'url',
        'label',
        'sort_order',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_id' => 'integer',
            'parent_id' => 'integer',
            'page_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<MenuItem, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function href(): ?string
    {
        if (is_null($this->page_id)) {
            return $this->url;
        }

        return $this->page?->url();
    }

    public function displayLabel(): string
    {
        if (! is_null($this->label) && $this->label !== '') {
            return $this->label;
        }

        return $this->page?->menuLabel() ?? (string) $this->url;
    }

    public function isPublic(): bool
    {
        if (is_null($this->page_id)) {
            return ! is_null($this->url) && $this->url !== '';
        }

        return $this->page?->isPublished() ?? false;
    }
}
