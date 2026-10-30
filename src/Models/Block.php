<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use J87NL\Cms\Blocks\BlockRegistry;
use J87NL\Cms\Blocks\BlockType;

/**
 * @property int $id
 * @property int $page_id
 * @property string $type
 * @property array<string, mixed> $data
 * @property int $sort_order
 * @property bool $is_visible
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Page $page
 */
class Block extends Model
{
    protected $table = 'cms_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'data',
        'sort_order',
        'is_visible',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_visible' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
            'page_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function blockType(): ?BlockType
    {
        return app(BlockRegistry::class)->get($this->type);
    }
}
