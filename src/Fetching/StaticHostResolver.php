<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Fetching;

/**
 * Fixed answers for tests: bind it in place of HostResolver, so code that
 * fetches through the package (link checks, an image from an address) under
 * Http::fake() never makes a real DNS lookup. A host it doesn't list
 * resolves to nothing, which the fetcher refuses.
 */
class StaticHostResolver extends HostResolver
{
    /**
     * @param  array<string, string|list<string>>  $hosts
     */
    public function __construct(protected array $hosts = []) {}

    /**
     * @return list<string>
     */
    public function addresses(string $host): array
    {
        return (array) ($this->hosts[strtolower($host)] ?? []);
    }
}
