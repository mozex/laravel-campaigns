<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Sending\Allowance;
use Mozex\Campaigns\Sending\Pace;
use Mozex\Campaigns\Sending\StopLoss;
use Mozex\Campaigns\Support\ShownText;

/**
 * Where a campaign's sending stands, as an agent polls it after a start and
 * while it watches: its status and times, why a start or a pause happened,
 * its messages by status, its failures by kind, the queue (a stall
 * included), and the pace and limits in effect with what the pace allows
 * now. Its settings as given are get_campaign's.
 */
class CampaignProgress
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Campaign $campaign): array
    {
        $queue = CampaignReport::queue($campaign);
        $stalled = $queue['stalled'] > 0;

        return [
            'uuid' => $campaign->uuid,
            'key' => $campaign->key,
            'name' => ShownText::of($campaign->name),
            'status' => $campaign->status->value,
            'recipient_count' => $campaign->recipient_count,
            'start_error' => ShownText::of($campaign->start_error, CampaignSummary::REASON_LENGTH),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'started_at' => $campaign->started_at?->toIso8601String(),
            'paused_at' => $campaign->paused_at?->toIso8601String(),
            'pause_reason' => ShownText::of($campaign->pause_reason, CampaignSummary::REASON_LENGTH),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'canceled_at' => $campaign->canceled_at?->toIso8601String(),
            'messages' => static::messages($campaign),
            'sends' => CampaignMessage::query()->where('campaign_id', $campaign->id)->whereNotNull('sent_at')->count(),
            'failures' => (object) static::failures($campaign),
            'queue' => $queue,
            'stalled' => $stalled,
            'pace' => static::pace($campaign, $stalled),
            'limits' => (object) StopLoss::thresholds($campaign),
        ];
    }

    /**
     * Its messages by status, every status named.
     *
     * @return array<string, int>
     */
    protected static function messages(Campaign $campaign): array
    {
        $counts = array_fill_keys(array_column(MessageStatus::cases(), 'value'), 0);

        $rows = CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Its failed messages by kind: a DeliveryFailure value, or not_accepted
     * for one the provider's logs never showed (the reconciler's verdict).
     *
     * @return array<string, int>
     */
    protected static function failures(Campaign $campaign): array
    {
        $rows = CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', MessageStatus::Failed)
            ->toBase()
            ->selectRaw('failure_reason, count(*) as total')
            ->groupBy('failure_reason')
            ->get();

        $failures = [];

        foreach ($rows as $row) {
            $kind = is_string($row->failure_reason) ? $row->failure_reason : DeliveryFailure::Other->value;
            $failures[$kind] = ($failures[$kind] ?? 0) + (int) $row->total;
        }

        ksort($failures);

        return $failures;
    }

    /**
     * The pace in effect (the campaign's own values over the config's), the
     * day of it the campaign is on, that day's volume, whether now is inside
     * its sending hours, and how many emails it may hand out this minute,
     * while it sends: none while sending is switched off or its queue is
     * stalled, since the pump then hands out nothing.
     *
     * @return array<string, mixed>
     */
    protected static function pace(Campaign $campaign, bool $stalled): array
    {
        $pace = Pace::resolve($campaign->pace);
        $started = $campaign->pace_started_at ?? $campaign->started_at;
        $day = $started === null ? null : intdiv(max(0, now()->getTimestamp() - $started->getTimestamp()), Allowance::DAY) + 1;

        return [
            ...$pace,
            'rate_halved_until' => $campaign->rate_halved_until?->isFuture() ? $campaign->rate_halved_until->toIso8601String() : null,
            'day' => $day,
            'day_volume' => $day === null ? null : Pace::dayVolume($pace, $day),
            'in_sending_hours' => Pace::isSendingTime($pace, now()),
            'allowance_now' => match (true) {
                $campaign->status !== CampaignStatus::Sending => null,
                ! config('campaigns.enabled') || $stalled => 0,
                default => Allowance::for($campaign, now()),
            },
        ];
    }
}
