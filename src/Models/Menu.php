<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Menu extends Model
{
    protected $table = 'cms_menus';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
    ];

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public static function forKey(string $key): self
    {
        return self::query()
            ->firstOrCreate([
                'key' => $key,
            ]);
    }

    public function label(): string
    {
        $key = sprintf('cms::menus.keys.%s', $this->key);
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $this->key;
    }
}
