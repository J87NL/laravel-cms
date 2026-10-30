<?php

declare(strict_types=1);

namespace J87NL\Cms\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A chunked MCP image upload in progress. The chunks are files on the disk of cms.media.chunked_uploads.disk, in
 * directory(); the row holds what media_upload_start was told.
 *
 * @property string $id
 * @property string $user_id
 * @property string $file_name
 * @property int $size
 * @property string $sha256
 * @property array<string, mixed> $media_attributes
 * @property CarbonInterface|null $finishing_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class MediaUploadSession extends Model
{
    use HasUuids;

    public const int CHUNK_BYTES = 2048;

    protected $table = 'cms_media_upload_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'file_name',
        'size',
        'sha256',
        'media_attributes',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'media_attributes' => 'array',
            'finishing_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MediaUploadSession>  $query
     */
    public function scopeOpenFor(Builder $query, string $userId): void
    {
        $query->where('user_id', $userId)
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<MediaUploadSession>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_at', '<=', now());
    }

    /**
     * The open upload with this id of this user; an upload of someone else, or one that expired, is not found.
     */
    public static function findOpen(string $id, string $userId): ?self
    {
        if (! str($id)->isUuid()) {
            return null;
        }

        return self::query()
            ->openFor($userId)
            ->find($id);
    }

    public function chunkCount(): int
    {
        return intdiv($this->size + self::CHUNK_BYTES - 1, self::CHUNK_BYTES);
    }

    public function chunkSize(int $index): int
    {
        return $index === $this->chunkCount() - 1
            ? $this->size - $index * self::CHUNK_BYTES
            : self::CHUNK_BYTES;
    }

    public function directory(): string
    {
        return sprintf('%s/%s', rtrim((string) config('cms.media.chunked_uploads.directory', 'cms-media-uploads'), '/'), $this->id);
    }

    public function chunkPath(int $index): string
    {
        return sprintf('%s/%05d.part', $this->directory(), $index);
    }

    /**
     * An upload expires this long after it started or after its last chunk. CarbonInterface, because an app may make
     * now() return CarbonImmutable with Date::use().
     */
    public static function newExpiry(): CarbonInterface
    {
        return now()->addMinutes((int) config('cms.media.chunked_uploads.ttl_minutes', 60));
    }

    public static function disk(): string
    {
        return (string) config('cms.media.chunked_uploads.disk', 'local');
    }
}
