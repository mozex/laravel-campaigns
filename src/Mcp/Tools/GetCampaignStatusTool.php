<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;

#[Name('get_campaign_status')]
#[Title('Get a campaign\'s sending status')]
#[Description('Where a campaign\'s sending stands: its status (read it after a start until it is sending, scheduled, or a draft with start_error), why a start failed or a pause happened, its messages by status, its failures by kind, the queue with any stall, and the pace and stop-loss limits in effect with what the pace allows this minute. Its settings as given are get_campaign\'s; its numbers in full are get_campaign_report\'s.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class GetCampaignStatusTool extends CampaignsTool
{
    use SendsCampaigns;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
        ];
    }

    public function perform(Request $request): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
        ]);

        return static::progress(Campaign::forKey($input['key']));
    }
}
