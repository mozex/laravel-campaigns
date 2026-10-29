<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Models\Subscriber;

/**
 * An app's audience that says a lot: a description longer than any answer
 * shows, all quotes, and a few described parameters, for measuring the
 * audience list at its longest.
 */
class WordyAudience implements Audience
{
    public function description(): string
    {
        return str_repeat('"', 1000);
    }

    public function parameters(JsonSchema $schema): array
    {
        return [
            'course' => $schema->string()->description('The key of a course the people bought, as the course list names it.')->required(),
            'after' => $schema->string()->description('Only purchases on or after this date, like 2026-01-31.'),
            'refunded' => $schema->boolean()->description('Whether to count refunded purchases too. Default false.'),
        ];
    }

    public function rules(): array
    {
        return ['course' => ['required', 'string'], 'after' => ['nullable', 'date'], 'refunded' => ['nullable', 'boolean']];
    }

    /** @return Builder<Subscriber> */
    public function query(array $parameters): Builder
    {
        return Subscriber::query();
    }
}
