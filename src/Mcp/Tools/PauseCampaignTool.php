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
use Mozex\Campaigns\Actions\PauseCampaignAction;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;

#[Name('pause_campaign')]
#[Title('Pause a campaign')]
#[Description('Pauses a sending or scheduled campaign at once: nothing more goes to the queue, and emails already queued wait. Use it as soon as something looks wrong; it never needs approval and works while sending is switched off. Pausing a paused campaign changes nothing. resume_campaign carries on.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[IsDestructive(false)]
class PauseCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
            'reason' => $schema->string()->description('Why, kept on the campaign as its pause_reason.'),
        ];
    }

    public function perform(Request $request, PauseCampaignAction $pause): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        return static::progress($pause->execute(Campaign::forKey($input['key']), $input['reason'] ?? null));
    }
}
