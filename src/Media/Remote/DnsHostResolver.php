<?php

declare(strict_types=1);

namespace J87NL\Cms\Media\Remote;

/**
 * Resolves with dns_get_record, which has no time limit of its own: a lookup that fails (for example a name server that
 * does not answer) gives no addresses instead of a second, slower lookup. Only an empty answer falls back to
 * gethostbynamel, which also reads /etc/hosts.
 */
class DnsHostResolver implements HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $address;
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}
