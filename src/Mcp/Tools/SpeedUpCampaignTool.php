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

#[Name('speed_up_campaign')]
#[Title('Speed a campaign up')]
#[Description('Changes a campaign\'s pace (before it starts, while scheduled, sending or paused) in any way, faster included, once the person you work for has approved the new pace knowing the sending domain\'s standing (get_overview) and the campaign\'s numbers so far. Only the values given change. Refused while sending is switched off.')]
#[IsIdempotent]
#[IsOpenWorld]
#[IsDestructive]
class SpeedUpCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    protected bool $requiresApproval = true;

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

        return static::progress($change->execute(Campaign::forKey($input['key']), static::pace($input['pace']), faster: true));
    }
}
