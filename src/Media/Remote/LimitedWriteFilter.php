<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

use php_user_filter;

/**
 * A write filter that drops every byte past the DownloadLimit given as its parameter, so the file never grows past the
 * limit, and marks the limit as exceeded. It never fails the write: on Guzzle 7 an exception from the curl write
 * callback would keep the transfer running until the timeout, so the progress callback stops the transfer instead.
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
            $consumed += $bucket->datalen;

            if ($limit instanceof DownloadLimit && ! $limit->accepts($bucket->datalen)) {
                continue;
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
