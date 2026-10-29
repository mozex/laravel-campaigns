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
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Reports\ListSummary;

#[Name('list_lists')]
#[Title('List lists')]
#[Description('Every list by key, 25 a page, with its counts (by status, how many may be mailed, and by engagement tier), its description, kind and default consent basis. Archived lists only when include_archived is true.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListListsTool extends CampaignsTool
{
    public const PER_PAGE = 25;

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
            'include_archived' => $schema->boolean()->description('Also list archived lists. Default false.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'lists' => $schema->array()->items(Schemas::list($schema)),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $page = (int) ($input['page'] ?? 1);

        $query = CampaignList::query()->when(
            ! ($input['include_archived'] ?? false),
            fn (Builder $query): Builder => $query->active(),
        );

        $total = (clone $query)->count();

        return Response::structured([
            'lists' => ListSummary::rows($query->orderBy('key')->forPage($page, static::PER_PAGE)->get(), details: true),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
