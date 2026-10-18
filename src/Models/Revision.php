<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $page_id
 * @property array{page: array<string, mixed>, blocks: list<array<string, mixed>>} $snapshot
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_by' => 'integer',
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
