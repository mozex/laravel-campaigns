<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
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
use Mozex\Campaigns\Models\Template;
use Mozex\Campaigns\Reports\TemplateSummary;

#[Name('list_templates')]
#[Title('List templates')]
#[Description('The emails to start from: the package\'s starter templates (read-only: duplicate one with create_template\'s from), then the app\'s own templates, its defaults first, '.ListTemplatesTool::PER_PAGE.' a page, each with its current revision\'s number and languages. Archived templates only when include_archived is true.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListTemplatesTool extends CampaignsTool
{
    public const PER_PAGE = 20;

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
            'include_archived' => $schema->boolean()->description('Also list archived templates. Default false.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'starters' => $schema->array()->items(Schemas::starter($schema)),
            'templates' => $schema->array()->items(Schemas::template($schema)),
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

        $query = Template::query()->when(
            ! ($input['include_archived'] ?? false),
            fn (Builder $query): Builder => $query->active(),
        );

        $total = (clone $query)->count();

        $templates = $query
            // Only the locales of each current email: its HTML stays unread.
            ->with(['currentRevision.contents' => fn (Relation $query): Relation => $query->select(['id', 'revision_id', 'locale'])])
            ->orderByDesc('is_default')
            ->orderBy('key')
            ->forPage($page, static::PER_PAGE)
            ->get();

        return Response::structured([
            'starters' => TemplateSummary::starters(),
            'templates' => $templates->map(fn (Template $template): array => TemplateSummary::row($template))->values()->all(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
