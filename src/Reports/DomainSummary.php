<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Sending\Allowance;
use Mozex\Campaigns\Sending\DomainMetrics;
use Mozex\Campaigns\Sending\StopLoss;

/**
 * The sending domain's standing, for an agent judging whether a campaign
 * may start or speed up: its last 24 hours from the app's own messages (the
 * stop-loss's measure, rates per send), its daily cap and what the last 24
 * hours used of it, and the provider's own counts for the last 30 days.
 */
class DomainSummary
{
    /** The provider's days, today's (UTC) included. */
    public const DAYS = 30;

    /**
     * @return array{summary: array<string, mixed>, notes: list<string>}
     */
    public static function for(string $domain): array
    {
        $notes = [];
        $metrics = null;

        // Without Mailgun's reading key nothing is asked: the overview already
        // says the key is missing.
        if (config('campaigns.provider') !== 'mailgun' || filled(config('campaigns.mailgun.api_key'))) {
            try {
                // Counted back in UTC, the provider's days: in a zone with
                // daylight saving, 29 local days can span 30 or 31 UTC ones.
                $metrics = app(CampaignProvider::class)->domainMetrics($domain, now()->utc()->subDays(static::DAYS - 1), now()->utc());
            } catch (CampaignsException $exception) {
                $notes[] = 'The provider\'s numbers for '.$domain.' couldn\'t be read: '.$exception->getMessage();
            }
        }

        return [
            'summary' => [
                'name' => $domain,
                'last_24_hours' => static::lastDay($domain),
                'daily_cap' => Allowance::domainUsage($domain, now()),
                'provider' => $metrics === null ? null : static::provider($metrics),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * The domain's emails the provider took in the last 24 hours, across its
     * campaigns, and what came of them so far.
     *
     * @return array{sends: int, hard_bounces: int, complaints: int, unsubscribes: int, hard_bounce_rate: ?float, complaint_rate: ?float, unsubscribe_rate: ?float}
     */
    protected static function lastDay(string $domain): array
    {
        $since = now()->subHours(StopLoss::DOMAIN_HOURS);
        $campaigns = StopLoss::domainCampaigns($domain, $since);

        $row = $campaigns->isEmpty() ? null : CampaignMessage::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('sent_at', '>', $since)
            ->toBase()
            ->selectRaw(
                'count(*) as sends, '
                .'sum(case when failure_reason = ? then 1 else 0 end) as hard_bounces, '
                .'sum(case when complained_at is not null then 1 else 0 end) as complaints, '
                .'sum(case when unsubscribed_at is not null then 1 else 0 end) as unsubscribes',
                [DeliveryFailure::HardBounce->value],
            )
            ->first();

        $sends = (int) ($row->sends ?? 0);
        $hard = (int) ($row->hard_bounces ?? 0);
        $complaints = (int) ($row->complaints ?? 0);
        $unsubscribes = (int) ($row->unsubscribes ?? 0);

        return [
            'sends' => $sends,
            'hard_bounces' => $hard,
            'complaints' => $complaints,
            'unsubscribes' => $unsubscribes,
            'hard_bounce_rate' => static::rate($hard, $sends),
            'complaint_rate' => static::rate($complaints, $sends),
            'unsubscribe_rate' => static::rate($unsubscribes, $sends),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function provider(DomainMetrics $metrics): array
    {
        $days = [];

        foreach ($metrics->days as $date => $counts) {
            $days[] = ['date' => $date, ...$counts];
        }

        return [
            'from' => array_key_first($metrics->days),
            'to' => array_key_last($metrics->days),
            'totals' => $metrics->totals(),
            'days' => $days,
        ];
    }

    /**
     * A share in percent to three places, or null with nothing sent.
     */
    protected static function rate(int $count, int $of): ?float
    {
        return $of === 0 ? null : round($count * 100 / $of, 3);
    }
}
