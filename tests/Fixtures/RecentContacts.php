<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Contracts\Audience;
use Mozex\Campaigns\Models\Subscriber;

/**
 * An audience over the package's own subscribers: contacts no app record
 * stands behind, added in the last given days.
 */
class RecentContacts implements Audience
{
    public function description(): string
    {
        return 'Contacts added recently.';
    }

    public function parameters(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('How many days back.'),
        ];
    }

    public function rules(): array
    {
        return ['days' => ['integer', 'min:1']];
    }

    /** @return Builder<Subscriber> */
    public function query(array $parameters): Builder
    {
        return Subscriber::query()
            ->whereNull('subscribable_type')
            ->where('created_at', '>=', now()->subDays((int) ($parameters['days'] ?? 30)));
    }
}
