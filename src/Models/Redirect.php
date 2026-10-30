<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use J87NL\Cms\Enums\RedirectSource;

/**
 * @property int $id
 * @property string $from_path
 * @property string $to
 * @property int $status_code
 * @property int $hits
 * @property CarbonInterface|null $last_hit_at
 * @property RedirectSource $source
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Redirect extends Model
{
    /**
     * @var list<int>
     */
    public const array STATUS_CODES = [
        301,
        302,
    ];

    protected $table = 'cms_redirects';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'from_path',
        'to',
        'status_code',
        'source',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status_code' => 301,
        'hits' => 0,
        'source' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
            'source' => RedirectSource::class,
        ];
    }

    public function fromUrl(): string
    {
        return sprintf('/%s', $this->from_path);
    }

    public function isExternal(): bool
    {
        return ! str_starts_with($this->to, '/');
    }

    public function recordHit(): void
    {
        self::query()
            ->whereKey($this->id)
            ->update([
                'hits' => $this->getConnection()->raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }
}
