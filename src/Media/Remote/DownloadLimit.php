<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

/**
 * Counts the bytes written to a download file and refuses the write that passes the limit.
 */
class DownloadLimit
{
    public int $written = 0;

    public bool $exceeded = false;

    public function __construct(public readonly int $maxBytes) {}

    public function accepts(int $bytes): bool
    {
        $this->written += $bytes;
        $this->exceeded = $this->exceeded || ($this->maxBytes > 0 && $this->written > $this->maxBytes);

        return ! $this->exceeded;
    }
}
