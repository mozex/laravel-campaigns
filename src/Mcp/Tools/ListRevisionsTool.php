<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
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
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Models\Revision;
use Mozex\Campaigns\Reports\RevisionSummary;

#[Name('list_revisions')]
#[Title('List an email\'s revisions')]
#[Description('A template\'s or a campaign\'s email history, newest first, '.ListRevisionsTool::PER_PAGE.' a page: each revision\'s number, note, author, languages and check results\' state. The highest number is the current email. get_template downloads a template\'s revision by its number; restore_revision brings one back.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class ListRevisionsTool extends CampaignsTool
{
    use NamesEmailOwner;

    public const PER_PAGE = 20;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'page' => $schema->integer()->min(1)->description('The page, from 1 (the default).'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'revisions' => $schema->array()->items($schema->object(Schemas::revisionRow($schema))),
            'page' => $schema->integer(),
            'pages' => $schema->integer(),
            'total' => $schema->integer(),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'page' => static::PAGE_RULES,
        ]);

        $owner = static::owner($input);
        $page = (int) ($input['page'] ?? 1);
        $total = $owner->revisions()->count();

        $revisions = $owner->revisions()
            ->with([
                // Only the locales: the HTML stays unread.
                'contents' => fn (Relation $query): Relation => $query->select(['id', 'revision_id', 'locale']),
                'frozenFrom:id,number',
            ])
            ->orderByDesc('number')
            ->forPage($page, static::PER_PAGE)
            ->get();

        return Response::structured([
            'revisions' => $revisions->map(fn (Revision $revision): array => RevisionSummary::row($revision))->values()->all(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / static::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
