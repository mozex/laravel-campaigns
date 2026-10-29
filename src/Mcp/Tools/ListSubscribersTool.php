<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Reports\SubscriberSummary;

#[Name('list_subscribers')]
#[Title('List subscribers')]
#[Description('Subscribers a page at a time, oldest first, narrowed by the built-in filters: '.Schemas::FILTERS.'. Pass next_cursor back as cursor for the next page. One person whole, with their consent history: get_subscriber.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListSubscribersTool extends CampaignsTool
{
    /**
     * A page this long stays under the answer's limit with every column at
     * its longest; a longer one may be refused.
     */
    public const PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'filters' => $schema->object()->description('The built-in filters, as the description says; none lists everyone.'),
            'cursor' => $schema->string()->description('next_cursor from the page before.'),
            'per_page' => $schema->integer()->min(1)->max(static::MAX_PER_PAGE)->description('Default '.static::PER_PAGE.'.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'subscribers' => $schema->array()->items(Schemas::subscriberRow($schema)),
            'total' => $schema->integer()->description('Everyone the filters match.'),
            'next_cursor' => $schema->string()->nullable()->description('Null on the last page.'),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'filters' => ['nullable', 'array'],
            'cursor' => ['nullable', 'string', 'regex:/^[0-9]{1,19}$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.static::MAX_PER_PAGE],
        ]);

        $perPage = (int) ($input['per_page'] ?? static::PER_PAGE);

        /** @var array<array-key, mixed> $filters */
        $filters = $request->get('filters') ?? [];
        $query = SubscriberFilters::fromArray($filters)->apply(Subscriber::query());
        $total = (clone $query)->count();

        $page = $query
            ->when(isset($input['cursor']), fn (Builder $query): Builder => $query->where('id', '>', (int) $input['cursor']))
            ->with('subscriptions')
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        $more = $page->count() > $perPage;
        $page = $page->take($perPage);

        return Response::structured([
            'subscribers' => SubscriberSummary::rows($page),
            'total' => $total,
            'next_cursor' => $more ? (string) $page->last()?->id : null,
        ]);
    }
}
