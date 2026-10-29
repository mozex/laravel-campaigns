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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignSummary;
use Mozex\Campaigns\Support\Links;

#[Name('get_campaign')]
#[Title('Get a campaign')]
#[Description('One campaign: its status, sender, target lists, audience (in counts), tracking, UTM tags, pace and limits, and its current email with each language\'s subject and preheader, its checks\' state and links valid '.Links::DOWNLOAD_MINUTES.' minutes to download its files. A draft\'s email changes through add_revision with campaign; get_preview_link opens it in a browser.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetCampaignTool extends CampaignsTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->required()->description('The campaign\'s key.'),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::campaign($schema),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
        ]);

        return Response::structured([
            'campaign' => CampaignSummary::detail(Campaign::forKey($input['key'])),
        ]);
    }
}
