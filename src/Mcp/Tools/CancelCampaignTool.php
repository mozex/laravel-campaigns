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
use Mozex\Campaigns\Actions\CancelCampaignAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;

#[Name('cancel_campaign')]
#[Title('Cancel a campaign')]
#[Description('Cancels a campaign for good, in any status but completed: nothing more of it is sent, and it can\'t be resumed (duplicate_campaign makes a new draft). Emails never sent stay counted as pending, so its numbers show how far it got. It works while sending is switched off. Cancelling a cancelled campaign changes nothing.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive]
class CancelCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
        ];
    }

    public function perform(Request $request, CancelCampaignAction $cancel): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
        ]);

        return static::progress($cancel->execute(Campaign::forKey($input['key'])));
    }
}
