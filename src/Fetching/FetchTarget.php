<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * One hop of a fetch after the guard passed it: the host as the URL names
 * it, the port, and the checked address the connection is pinned to.
 */
readonly class FetchTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /**
     * The entry for curl's CURLOPT_RESOLVE: host:port:address, an IPv6
     * address in brackets.
     */
    public function resolveEntry(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->host}:{$this->port}:{$address}";
    }
}
