<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\RestoreRevisionAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\NamesEmailOwner;
use Mozex\Campaigns\Reports\RevisionSummary;

#[Name('restore_revision')]
#[Title('Restore an earlier version of an email')]
#[Description('Brings back an earlier revision of a template\'s or a draft campaign\'s email by saving a copy of it as the newest, so nothing is lost. The copy must meet today\'s rules, like any new revision.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class RestoreRevisionTool extends CampaignsTool
{
    use NamesEmailOwner;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...static::ownerInput($schema),
            'revision' => $schema->integer()->min(1)->required()->description('The number of the revision to bring back.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'revision' => $schema->object(Schemas::revisionRow($schema))->description('The new current revision.'),
        ];
    }

    public function perform(Request $request, RestoreRevisionAction $restore): ResponseFactory
    {
        $input = $request->validate([
            ...static::ownerRules(),
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        $revision = static::revision(static::owner($input), (int) $input['revision']);

        return Response::structured([
            'revision' => RevisionSummary::row($restore->execute($revision, static::author())),
        ]);
    }
}
