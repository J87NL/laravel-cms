<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property int $id
 * @property string $alt
 * @property string|null $caption
 * @property int $focal_x
 * @property int $focal_y
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaAsset extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const string COLLECTION = 'file';

    /**
     * @var list<string>
     */
    public const array ACCEPTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    protected $table = 'cms_media_assets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'alt',
        'caption',
        'focal_x',
        'focal_y',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'focal_x' => 50,
        'focal_y' => 50,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'focal_x' => 'integer',
            'focal_y' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->singleFile()
            ->useDisk((string) config('cms.media.disk', 'public'))
            ->acceptsMimeTypes(self::ACCEPTED_MIME_TYPES);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        foreach (self::widths() as $width) {
            $conversion = $this->addMediaConversion(self::conversionName($width))
                ->performOnCollections(self::COLLECTION)
                ->queued();

            $conversion->fit(Fit::Max, $width);
            $conversion->format('webp');
        }
    }

    /**
     * @return list<int>
     */
    public static function widths(): array
    {
        $widths = config('cms.media.widths', []);

        if (! is_array($widths)) {
            return [];
        }

        $widths = array_values(array_filter(array_map('intval', $widths), fn (int $width): bool => $width > 0));

        sort($widths);

        return $widths;
    }

    public static function conversionName(int $width): string
    {
        return sprintf('w%d', $width);
    }

    public function file(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    public function width(): int
    {
        return (int) $this->file()?->getCustomProperty('width', 0);
    }

    public function height(): int
    {
        return (int) $this->file()?->getCustomProperty('height', 0);
    }

    public function originalUrl(): ?string
    {
        return $this->file()?->getUrl();
    }

    public function objectPosition(): string
    {
        return sprintf('%d%% %d%%', $this->focal_x, $this->focal_y);
    }

    /**
     * @return list<array{url: string, width: int}>
     */
    public function sources(): array
    {
        $media = $this->file();

        if (is_null($media)) {
            return [];
        }

        $originalWidth = $this->width();
        $sources = [];

        foreach (self::widths() as $width) {
            $name = self::conversionName($width);

            if (! $media->hasGeneratedConversion($name)) {
                continue;
            }

            $renderedWidth = $originalWidth > 0 ? min($width, $originalWidth) : $width;

            if (array_key_exists($renderedWidth, $sources)) {
                continue;
            }

            $sources[$renderedWidth] = [
                'url' => $media->getUrl($name),
                'width' => $renderedWidth,
            ];
        }

        if ($sources !== []) {
            return array_values($sources);
        }

        return [
            [
                'url' => $media->getUrl(),
                'width' => $originalWidth,
            ],
        ];
    }

    public function thumbnailUrl(): ?string
    {
        return $this->sources()[0]['url'] ?? null;
    }
}
