<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
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
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignSummary;

#[Name('list_campaigns')]
#[Title('List campaigns')]
#[Description('The app\'s campaigns, newest first, '.ListCampaignsTool::PER_PAGE.' a page, in any status unless statuses are given: drafts, scheduled, sending, paused, completed and cancelled ones. get_campaign shows one whole.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListCampaignsTool extends CampaignsTool
{
    public const PER_PAGE = 20;

    public function schema(JsonSchema $schema): array
    {
        return [
            'statuses' => $schema->array()->items($schema->string()->enum(array_column(CampaignStatus::cases(), 'value')))->description('Only campaigns in these statuses.'),
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaigns' => $schema->array()->items($schema->object(Schemas::campaignRow($schema))),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'statuses' => ['nullable', 'array', 'list'],
            'statuses.*' => ['string', Rule::enum(CampaignStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = (int) ($input['page'] ?? 1);
        $query = Campaign::query()->when($input['statuses'] ?? [], fn (Builder $query, array $statuses): Builder => $query->whereIn('status', $statuses));
        $total = (clone $query)->count();

        return Response::structured([
            'campaigns' => CampaignSummary::rows($query->orderByDesc('id')->forPage($page, static::PER_PAGE)->get()),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
