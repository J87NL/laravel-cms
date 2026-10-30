<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

use php_user_filter;

/**
 * A write filter that fails the write which passes the DownloadLimit given as its parameter, so curl stops the
 * transfer with a write error. It sees the bytes after content decoding, so a compressed body cannot slip past it.
 */
class LimitedWriteFilter extends php_user_filter
{
    public const string NAME = 'cms.limited-write';

    public static function register(): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $limit = $this->params;

        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($limit instanceof DownloadLimit && ! $limit->accepts($bucket->datalen)) {
                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
