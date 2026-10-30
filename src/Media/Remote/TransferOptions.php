<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

use Closure;
use GuzzleHttp\ClientInterface;

/**
 * The Guzzle request options for one source_url request.
 *
 * - No proxy and the host pinned to the checked address with CURLOPT_RESOLVE, the only raw curl option Guzzle 8
 *   accepts for this, so the name is never resolved again.
 * - No content decoding (and Accept-Encoding: identity), so the bytes on the wire are the bytes stored, and the
 *   progress callback can stop the transfer as soon as more bytes arrive than allowed. A compressed answer is refused
 *   by the downloader.
 * - The progress callback is Guzzle's progress option on Guzzle 8, which aborts when it returns true. Guzzle 7 ignores
 *   that answer, so there the raw curl progress function aborts instead (Guzzle 7 accepts it with a deprecation
 *   notice).
 * - The sink is the target file behind LimitedWriteFilter, which keeps the file within the limit whatever happens.
 */
class TransferOptions
{
    /**
     * @param  resource  $sink
     * @param  array<int, list<string>>  $curlResolve  [CURLOPT_RESOLVE => [...]] or []
     * @param  Closure(int): void  $onTooLarge
     * @return array<string, mixed>
     */
    public static function build($sink, array $curlResolve, int $maxBytes, Closure $onTooLarge, ?int $guzzleMajorVersion = null): array
    {
        $exceeds = function (int $downloadTotal, int $downloaded) use ($maxBytes, $onTooLarge): bool {
            $bytes = max($downloadTotal, $downloaded);

            if ($maxBytes <= 0 || $bytes <= $maxBytes) {
                return false;
            }

            $onTooLarge($bytes);

            return true;
        };

        $options = [
            'allow_redirects' => false,
            'proxy' => '',
            'decode_content' => false,
            'sink' => $sink,
            'curl' => $curlResolve,
        ];

        if (($guzzleMajorVersion ?? self::guzzleMajorVersion()) >= 8) {
            return [...$options, 'progress' => fn (int $downloadTotal, int $downloaded): bool => $exceeds($downloadTotal, $downloaded)];
        }

        $options['curl'] = $curlResolve + [
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn (mixed $handle, int $downloadTotal, int $downloaded): int => $exceeds($downloadTotal, $downloaded) ? 1 : 0,
        ];

        return $options;
    }

    public static function guzzleMajorVersion(): int
    {
        return defined(ClientInterface::class.'::MAJOR_VERSION') ? (int) constant(ClientInterface::class.'::MAJOR_VERSION') : 7;
    }
}
