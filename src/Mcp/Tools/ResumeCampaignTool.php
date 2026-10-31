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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\ResumeCampaignAction;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;

#[Name('resume_campaign')]
#[Title('Resume a paused campaign')]
#[Description('Resumes a paused campaign where it stopped, once the person you work for has approved it knowing why it paused: an automatic pause names the numbers and the limit that stopped it, and resuming counts its limits again from that pause. One paused before its scheduled time that is still ahead waits for it again; after a pause of more than a day the pace starts again from its first day. Refused while sending is switched off.')]
#[IsOpenWorld]
#[IsDestructive]
class ResumeCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::progress($schema),
            'warning' => static::warningOutput($schema),
        ];
    }

    public function perform(Request $request, ResumeCampaignAction $resume): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
        ]);

        return static::progress($resume->execute(Campaign::forKey($input['key'])), warn: true);
    }
}
