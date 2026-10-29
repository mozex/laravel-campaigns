<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * Looks up the addresses a host name resolves to, IPv4 and IPv6, before the
 * server fetches from it. Bound in the container, so a test can bind
 * StaticHostResolver instead and never touch real DNS.
 */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        $addresses = [];

        // A failed lookup warns and returns false; it is an empty answer here.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        // The system resolver as well, which also reads the hosts file: a
        // name listed there can't hide behind a public DNS answer.
        $system = @gethostbynamel($host);

        foreach (is_array($system) ? $system : [] as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique(array_filter($addresses, 'is_string')));
    }
}
