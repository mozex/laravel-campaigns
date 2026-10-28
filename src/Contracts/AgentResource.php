<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Illuminate\Contracts\Pagination\Paginator;

/**
 * Read-only data from the app that agents may read to choose an audience or
 * write an email, such as the products an app sells. Listed in
 * campaigns.resources.
 */
interface AgentResource
{
    /**
     * What the data is and what it helps with, for an agent deciding
     * whether to read it.
     */
    public function description(): string;

    /**
     * One page of the data, read as JSON, so a long list never arrives at
     * once: return the app's query paginated, such as
     * Product::query()->simplePaginate(50, page: $page)->through(fn (Product $product): array => [...]).
     * Everything in the items is sent, so map each to the fields an agent
     * needs.
     *
     * @return Paginator<array-key, mixed>
     */
    public function read(int $page): Paginator;
}
