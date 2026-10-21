<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use J87NL\Cms\Enums\PageStatus;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $slug
 * @property string $path
 * @property string $title
 * @property string|null $menu_title
 * @property PageStatus $status
 * @property Carbon|null $publish_at
 * @property Carbon|null $unpublish_at
 * @property string|null $schedule_error
 * @property bool $is_home
 * @property int $sort_order
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int|null $og_image_id
 * @property string|null $canonical_url
 * @property bool $noindex
 * @property string|null $schema_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Page|null $parent
 * @property-read Revision|null $pendingRevision
 */
class Page extends Model
{
    /**
     * @var list<string>
     */
    public const array CONTENT_ATTRIBUTES = [
        'parent_id',
        'slug',
        'title',
        'menu_title',
        'meta_title',
        'meta_description',
        'og_image_id',
        'canonical_url',
        'noindex',
        'schema_type',
    ];

    protected $table = 'cms_pages';

    /**
     * @var list<string>
     */
    protected $fillable = self::CONTENT_ATTRIBUTES;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'is_home' => false,
        'noindex' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'publish_at' => 'datetime',
            'unpublish_at' => 'datetime',
            'is_home' => 'boolean',
            'noindex' => 'boolean',
            'sort_order' => 'integer',
            'parent_id' => 'integer',
            'og_image_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Page $page): void {
            $page->path = $page->computePath();
        });
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<Revision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class)
            ->where('is_pending', false)
            ->orderByDesc('id');
    }

    /**
     * @return HasOne<Revision, $this>
     */
    public function pendingRevision(): HasOne
    {
        return $this->hasOne(Revision::class)
            ->where('is_pending', true)
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', PageStatus::Published->value);
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published;
    }

    public function url(): string
    {
        if ($this->is_home) {
            return '/';
        }

        return sprintf('/%s', $this->path);
    }

    public function menuLabel(): string
    {
        return is_null($this->menu_title) || $this->menu_title === '' ? $this->title : $this->menu_title;
    }

    public function computePath(): string
    {
        if (is_null($this->parent_id)) {
            return $this->slug;
        }

        $parentPath = self::query()
            ->whereKey($this->parent_id)
            ->value('path');

        if (! is_string($parentPath) || $parentPath === '') {
            return $this->slug;
        }

        return sprintf('%s/%s', $parentPath, $this->slug);
    }

    public function isAncestorOf(Page $page): bool
    {
        return str_starts_with($page->path, sprintf('%s/', $this->path));
    }
}
