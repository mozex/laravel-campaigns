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
use Mozex\Campaigns\Actions\UpdateCampaignAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\DescribesCampaignSettings;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignSummary;

#[Name('update_campaign')]
#[Title('Update a draft campaign')]
#[Description('Changes a draft campaign\'s settings: only those given change (null clears from_name or reply_to), and pace and thresholds merge into what it has. Its lists and audience are checked together, a named audience\'s parameters included. Once started or scheduled only its pace changes, through the pace tools. Its email changes through add_revision with campaign.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class UpdateCampaignTool extends CampaignsTool
{
    use DescribesCampaignSettings;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('The campaign\'s key.'),
            'settings' => static::settingsInput($schema)->required(),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::campaign($schema),
        ];
    }

    public function perform(Request $request, UpdateCampaignAction $update): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'settings' => ['present', 'array'],
        ]);

        $campaign = $update->execute(Campaign::forKey($input['key']), static::settings($input['settings']));

        return Response::structured([
            'campaign' => CampaignSummary::detail($campaign),
        ]);
    }
}
