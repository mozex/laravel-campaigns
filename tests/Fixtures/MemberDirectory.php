<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Contracts\Pagination\Paginator;
use Mozex\Campaigns\Contracts\AgentResource;

/**
 * An app's read-only data for agents: its members' names, two to a page.
 */
class MemberDirectory implements AgentResource
{
    public function description(): string
    {
        return 'The members, by name.';
    }

    public function read(int $page): Paginator
    {
        return Member::query()
            ->orderBy('id')
            ->simplePaginate(2, page: $page)
            ->through(fn (Member $member): array => ['name' => $member->name]);
    }
}
