<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use J87NL\Cms\Enums\EntityType;

/**
 * @property int $id
 * @property string|null $site_name
 * @property string|null $title_pattern
 * @property string|null $default_meta_description
 * @property EntityType $entity_type
 * @property string|null $entity_name
 * @property string|null $entity_job_title
 * @property string|null $entity_email
 * @property list<string>|null $entity_same_as
 * @property int|null $entity_image_id
 * @property int|null $og_image_id
 * @property string|null $robots_extra
 * @property string|null $llms_intro
 * @property string|null $contact_recipient
 * @property int|null $privacy_page_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Page|null $privacyPage
 */
class SiteSettings extends Model
{
    public const string TITLE_PLACEHOLDER = ':title';

    /**
     * @var list<string>
     */
    public const array EDITABLE_ATTRIBUTES = [
        'site_name',
        'title_pattern',
        'default_meta_description',
        'entity_type',
        'entity_name',
        'entity_job_title',
        'entity_email',
        'entity_same_as',
        'entity_image_id',
        'og_image_id',
        'robots_extra',
        'llms_intro',
        'contact_recipient',
        'privacy_page_id',
    ];

    protected $table = 'cms_settings';

    /**
     * @var list<string>
     */
    protected $fillable = self::EDITABLE_ATTRIBUTES;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'entity_type' => 'Person',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_type' => EntityType::class,
            'entity_same_as' => 'array',
            'entity_image_id' => 'integer',
            'og_image_id' => 'integer',
            'privacy_page_id' => 'integer',
        ];
    }

    public static function current(): self
    {
        $settings = self::query()
            ->orderBy('id')
            ->first();

        return $settings ?? new self;
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function privacyPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'privacy_page_id');
    }

    public function siteName(): string
    {
        $name = trim((string) $this->site_name);

        return $name === '' ? (string) config('app.name') : $name;
    }

    public function entityName(): string
    {
        $name = trim((string) $this->entity_name);

        return $name === '' ? $this->siteName() : $name;
    }

    public function title(string $pageTitle): string
    {
        if ($pageTitle === '' || $pageTitle === $this->siteName()) {
            return $this->siteName();
        }

        $pattern = trim((string) $this->title_pattern);

        if (! str_contains($pattern, self::TITLE_PLACEHOLDER)) {
            $pattern = sprintf('%s | %s', self::TITLE_PLACEHOLDER, $this->siteName());
        }

        return str_replace(self::TITLE_PLACEHOLDER, $pageTitle, $pattern);
    }

    /**
     * @return list<string>
     */
    public function sameAs(): array
    {
        $urls = $this->entity_same_as ?? [];

        return array_values(array_filter(
            array_map(fn (mixed $url): string => trim((string) $url), $urls),
            fn (string $url): bool => $url !== '',
        ));
    }

    /**
     * @return list<string>
     */
    public function robotsLines(): array
    {
        $lines = preg_split('/\R/', (string) $this->robots_extra) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            fn (string $line): bool => $line !== '',
        ));
    }

    public function privacyUrl(): ?string
    {
        $page = $this->privacyPage;

        if (is_null($page) || ! $page->isPublished()) {
            return null;
        }

        return $page->url();
    }
}
