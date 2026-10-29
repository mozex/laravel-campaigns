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
use Mozex\Campaigns\Actions\DuplicateCampaignAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Support\Keys;

#[Name('duplicate_campaign')]
#[Title('Duplicate a campaign')]
#[Description('A new draft with another campaign\'s settings, lists, audience and email, whatever status the other is in. The email is its current revision as its author made it, never the copy a start made with UTM tags, so the new campaign\'s links get its own tags when it starts.')]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class DuplicateCampaignTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->required()->description('The key of the campaign to copy.'),
            'key' => $schema->string()->required()->description('The new campaign\'s key: lower-case letters, digits, dots, dashes and underscores, at most '.Keys::MAX_LENGTH.' characters.'),
            'name' => $schema->string()->required(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::campaign($schema),
        ];
    }

    public function perform(Request $request, DuplicateCampaignAction $duplicate): ResponseFactory
    {
        $input = $request->validate([
            'from' => ['required', 'string'],
            'key' => ['required', 'string'],
            'name' => ['required', 'string'],
        ]);

        $campaign = $duplicate->execute(Campaign::forKey($input['from']), $input['key'], $input['name'], static::author());

        return Response::structured([
            'campaign' => CampaignSummary::detail($campaign),
        ]);
    }
}
