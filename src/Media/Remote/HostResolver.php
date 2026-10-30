<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the host name resolves to; empty when it does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
