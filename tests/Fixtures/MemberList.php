<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mozex\Campaigns\Contracts\ListSource;

class MemberList implements ListSource
{
    public function name(): string
    {
        return 'Members';
    }

    /** @return Builder<Member> */
    public function query(): Builder
    {
        return Member::query();
    }

    public function isEligible(Model $record): bool
    {
        return $record->getAttribute('banned_at') === null;
    }

    public function fields(Model $record): array
    {
        $name = $record->getAttribute('name');

        return [
            'email' => $record->getAttribute('email'),
            'name' => $name,
            'first_name' => $name === null ? null : explode(' ', $name)[0],
            'locale' => $record->getAttribute('locale'),
            'country' => $record->getAttribute('country'),
        ];
    }
}
