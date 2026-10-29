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
use Mozex\Campaigns\Models\Asset;
use Mozex\Campaigns\Reports\AssetSummary;

#[Name('list_assets')]
#[Title('List images')]
#[Description('The asset library: every image an email can use, newest first, '.ListAssetsTool::PER_PAGE.' a page, with its public address, size and dimensions. search finds images whose name or address contains it, so an image\'s address finds its details. Images are never deleted, since delivered mail keeps loading them.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListAssetsTool extends CampaignsTool
{
    /** Each address may be 2,048 characters, so a page stays short. */
    public const PER_PAGE = 15;

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of a name or an address.'),
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'assets' => $schema->array()->items(Schemas::asset($schema)),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:2048'],
            'page' => static::PAGE_RULES,
        ]);

        $page = (int) ($input['page'] ?? 1);
        $search = trim((string) ($input['search'] ?? ''));

        // Its % and _ match anything, as LIKE's own do: harmless in a search.
        $query = Asset::query()->when($search !== '', fn (Builder $query): Builder => $query->where(
            fn (Builder $query): Builder => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('url', "%{$search}%"),
        ));

        $total = (clone $query)->count();

        return Response::structured([
            'assets' => $query->orderByDesc('id')->forPage($page, static::PER_PAGE)->get()
                ->map(fn (Asset $asset): array => AssetSummary::row($asset))
                ->values()
                ->all(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
