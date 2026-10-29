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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\ArchiveListAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Reports\ListSummary;

#[Name('archive_list')]
#[Title('Archive or restore a list')]
#[Description('Archives a static list, or restores it with archived false. An archived list is hidden from the lists to pick from and takes no more contacts from agents; nothing is deleted, its people stay on it, and campaigns that target it go on.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class ArchiveListTool extends CampaignsTool
{
    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required(),
            'archived' => $schema->boolean()->description('False restores it. Default true.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'changed' => $schema->boolean()->description('False when the list was already in that state.'),
            'list' => Schemas::list($schema),
        ];
    }

    public function perform(Request $request, ArchiveListAction $archive): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $list = CampaignList::forKey($input['key']);
        $changed = $archive->execute($list, (bool) ($input['archived'] ?? true));

        return Response::structured([
            'changed' => $changed,
            'list' => ListSummary::rows([$list], details: true)[0],
        ]);
    }
}
