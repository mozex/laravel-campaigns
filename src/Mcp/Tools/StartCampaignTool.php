<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Mozex\Campaigns\Actions\StartCampaignAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\Concerns\SendsCampaigns;
use Mozex\Campaigns\Models\Campaign;
use Throwable;

#[Name('start_campaign')]
#[Title('Start or schedule a campaign')]
#[Description('Starts a draft campaign now, or schedules it for a time, once the person you work for has approved the exact number of recipients and the pace. recipients must be the number preview_audience gave: the start freezes the audience and is handed back to a draft (start_error) when the count differs, so nobody uncounted is mailed. Its email is frozen with its UTM tags; its pace may be set here too. It answers at once with status starting: read get_campaign_status until it is sending, scheduled, or a draft with start_error. Refused while sending is switched off.')]
#[IsOpenWorld]
#[IsDestructive]
class StartCampaignTool extends CampaignsTool
{
    use SendsCampaigns;

    protected bool $requiresApproval = true;

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => static::keyInput($schema),
            'recipients' => $schema->integer()->required()->description('The recipient count preview_audience gave, as the person approved it.'),
            'at' => $schema->string()->description('When to start, as an ISO 8601 time with its offset (2026-11-02T09:00:00+01:00 or ...Z); now when left out or up to '.StartCampaignAction::PAST_MINUTES.' minutes past, refused further back. At most a year ahead.'),
            'pace' => static::paceInput($schema),
        ];
    }

    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::progress($schema),
            'warning' => static::warningOutput($schema),
        ];
    }

    public function perform(Request $request, StartCampaignAction $start): ResponseFactory
    {
        $input = $request->validate([
            'key' => ['required', 'string'],
            'recipients' => ['required', 'integer', 'min:1'],
            'at' => ['nullable', 'string', 'max:64'],
            'pace' => ['nullable', 'array'],
        ]);

        $campaign = $start->execute(
            Campaign::forKey($input['key']),
            (int) $input['recipients'],
            static::time($input['at'] ?? null),
            isset($input['pace']) ? static::pace($input['pace']) : null,
        );

        return static::progress($campaign, warn: true);
    }

    /**
     * A time given with its offset: one without would be read in the app's
     * timezone, which the agent may not know.
     *
     * @throws CampaignsException
     */
    protected static function time(?string $at): ?CarbonImmutable
    {
        $at = trim((string) $at);

        if ($at === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})\z/i', $at) !== 1) {
            throw CampaignsException::invalidValue('time', 'must be an ISO 8601 time with its offset, like 2026-11-02T09:00:00+01:00 or 2026-11-02T08:00:00Z');
        }

        try {
            $time = CarbonImmutable::parse($at);
        } catch (Throwable) {
            $time = null;
        }

        // A day past the month's end parses as the next month's, so the date
        // read back must be the one given (the time keeps its offset).
        if ($time === null || $time->format('Y-m-d') !== substr($at, 0, 10)) {
            throw CampaignsException::invalidValue('time', 'isn\'t a real date and time');
        }

        return $time;
    }
}
