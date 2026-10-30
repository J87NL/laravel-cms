<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property array{page: array<string, mixed>, blocks: list<array<string, mixed>>} $snapshot
 * @property bool $is_pending
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Page $page
 * @property-read Model|null $author
 */
class Revision extends Model
{
    protected $table = 'cms_revisions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'snapshot',
        'is_pending',
        'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_pending' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'is_pending' => 'boolean',
            'created_by' => 'integer',
            'page_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<Revision>  $query
     */
    public function scopeHistory(Builder $query): void
    {
        $query->where('is_pending', false);
    }

    /**
     * @param  Builder<Revision>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('is_pending', true);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'created_by');
    }

    /**
     * @return class-string<Model>
     */
    public static function userModel(): string
    {
        $configured = config('cms.user_model') ?? config('auth.providers.users.model');

        if (is_string($configured) && is_subclass_of($configured, Model::class)) {
            return $configured;
        }

        return Model::class;
    }
}
