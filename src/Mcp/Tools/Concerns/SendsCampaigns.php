<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Reports\CampaignProgress;
use Mozex\Campaigns\Reports\GmailReading;
use Mozex\Campaigns\Reports\Overview;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Sending\Pace;
use Throwable;

/**
 * What the sending tools share: a campaign named by its key, and an answer
 * showing where its sending stands after the call (as get_campaign_status
 * shows it). Starting, resuming and speeding up also answer the readings an
 * agent checks (Gmail's spam rate for the sending domain, the provider's
 * usage) and what they warn of, starting and resuming also when the driver
 * reads nothing back from its service, which the stop-loss and the blocks
 * depend on.
 */
trait SendsCampaigns
{
    /**
     * @return array<string, Type>
     */
    protected function output(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::progress($schema),
        ];
    }

    /**
     * The output of a tool answering with checked().
     *
     * @return array<string, Type>
     */
    protected static function checkedOutput(JsonSchema $schema): array
    {
        return [
            'campaign' => Schemas::progress($schema),
            'readings' => Schemas::readings($schema),
            'warnings' => $schema->array()->items($schema->string())->description('What the readings and the driver warn of, in words: Gmail\'s spam rate at or above 0.1% (at or above 0.3%, Google\'s stop line), a campaign that would take the account past its plan or quota, and, for a start or a resume, a driver that reads nothing back from its service. Tell the person you work for.'),
        ];
    }

    protected static function keyInput(JsonSchema $schema): Type
    {
        return $schema->string()->required()->description('The campaign\'s key.');
    }

    protected static function paceInput(JsonSchema $schema): Type
    {
        return $schema->object()->description(Schemas::PACE);
    }

    protected static function progress(Campaign $campaign): ResponseFactory
    {
        return Response::structured([
            'campaign' => CampaignProgress::of($campaign),
        ]);
    }

    /**
     * The campaign's answer with the readings and their warnings. They are
     * read after the change, which is already made, so one that fails is a
     * warning, never a refusal.
     *
     * @param  ?int  $approved  the recipients a start was approved for, which its freeze makes into messages
     */
    protected static function checked(Campaign $campaign, bool $readBack, ?int $approved = null): ResponseFactory
    {
        $warnings = [];

        try {
            // A driver an app registered may fail to build with anything.
            $warnings[] = $readBack ? Overview::readsNothingBack() : null;
            $gmail = GmailReading::for($campaign->sendingDomain());
            $usage = ProviderUsage::read();
            $readings = ['gmail' => $gmail, 'usage' => $usage];

            $warnings[] = is_string($gmail['warning'] ?? null) ? $gmail['warning'] : null;
            $warnings[] = ProviderUsage::warning($usage, static::unsent($campaign, $approved), Pace::resolve($campaign->pace)['daily_cap']);
        } catch (Throwable $exception) {
            report($exception);
            $readings = null;
            $warnings[] = 'The readings couldn\'t be read ('.class_basename($exception).'), so nothing here says how Gmail\'s spam rate, the provider\'s usage or the driver stand: read get_overview before going on.';
        }

        return Response::structured([
            'campaign' => CampaignProgress::of($campaign),
            'readings' => $readings,
            'warnings' => array_values(array_filter($warnings, is_string(...))),
        ]);
    }

    /**
     * The campaign's emails still to send: a starting campaign's approved
     * count, since its freeze is queued and its messages don't exist yet;
     * none for one that won't send.
     */
    protected static function unsent(Campaign $campaign, ?int $approved): ?int
    {
        return match ($campaign->status) {
            CampaignStatus::Starting => $approved,
            CampaignStatus::Draft, CampaignStatus::Completed, CampaignStatus::Canceled => null,
            default => CampaignMessage::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', [MessageStatus::Pending, MessageStatus::Queued, MessageStatus::Sending])
                ->count(),
        };
    }

    /**
     * The pace as given: an empty object arrives as an empty array.
     *
     * @return array<string, mixed>
     */
    protected static function pace(mixed $given): array
    {
        /** @var array<string, mixed> */
        return is_array($given) ? $given : [];
    }
}
