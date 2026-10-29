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
use Mozex\Campaigns\Actions\ChangePaceAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;

#[Name('slow_down_campaign')]
#[Title('Slow a campaign down')]
#[Description('Changes a campaign\'s pace (before it starts, while scheduled, sending or paused) to one that sends no faster in any way: a change that raises a number, lifts or raises the daily cap, or reaches a sending time the old hours didn\'t (a new timezone counts) is refused, and goes through speed_up_campaign. Only the values given change. It works while sending is switched off.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class SlowDownCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
            'pace' => static::paceInput($schema)->required(),
        ];
    }

    public function perform(Request $request, ChangePaceAction $change): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'pace' => ['present', 'array'],
        ]);

        return static::progress($change->execute(Campaign::forKey($input['key']), static::pace($input['pace']), faster: false));
    }
}
