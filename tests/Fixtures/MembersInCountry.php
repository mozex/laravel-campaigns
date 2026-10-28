<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Contracts\Audience;

/**
 * An app's audience over its own records: the members in one country.
 */
class MembersInCountry implements Audience
{
    public function description(): string
    {
        return 'Members who live in a country.';
    }

    public function parameters(JsonSchema $schema): array
    {
        return [
            'country' => $schema->string()->description('A two-letter country code.')->required(),
        ];
    }

    public function rules(): array
    {
        return ['country' => ['required', 'string', 'size:2']];
    }

    /** @return Builder<Member> */
    public function query(array $parameters): Builder
    {
        return Member::query()->where('country', strtoupper((string) $parameters['country']))->latest();
    }
}
