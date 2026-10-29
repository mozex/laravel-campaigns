<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;

/**
 * The limits a campaign pauses itself at (campaigns.stop_loss, under the
 * campaign's own thresholds), and what they measure: its emails the provider
 * took (a message whose sent_at is set), its hard bounces, spam reports and
 * unsubscribes, read from its messages, never from the provider's events.
 * Before early_until emails: early_hard_bounces hard bounces, or
 * early_hard_bounce_rate percent of at least early_min_sends; from then on,
 * more than hard_bounce_rate percent of its last hard_bounce_window. Before
 * complaints_until emails: `complaints` spam reports; from then on, more than
 * complaint_rate percent. More than unsubscribe_rate percent unsubscribes
 * once it has sent unsubscribe_min_sends. A limit "reached" counts at the
 * value, one "passed" only above it, and a limit trips only on at least one
 * of what it counts.
 *
 * The same bounce and complaint limits, the config's own, apply to a sending
 * domain's last 24 hours across its campaigns. What either measures starts
 * again when the stop-loss pauses for it (limits_reset_at for a campaign,
 * domain_limits_reset_at for its domain), so a campaign someone resumes
 * isn't paused again at its next bounce for the numbers that paused it.
 */
class StopLoss
{
    /**
     * The window a sending domain is measured over, in hours.
     */
    public const DOMAIN_HOURS = 24;

    /**
     * The limits a campaign is held to: the config's, under its own.
     *
     * @return array<string, int|float>
     */
    public static function thresholds(?Campaign $campaign = null): array
    {
        /** @var array<string, int|float> $config */
        $config = (array) config('campaigns.stop_loss', []);

        /** @var array<string, int|float> $own */
        $own = $campaign->thresholds ?? [];

        return [...$config, ...$own];
    }

    /**
     * Why the campaign must pause, or null while it is within its limits.
     */
    public static function campaignBreach(Campaign $campaign): ?string
    {
        $limits = static::thresholds($campaign);
        $sends = static::campaignSends($campaign);
        $totals = static::totals($sends);

        return static::bounceBreach($sends, $totals, $limits)
            ?? static::complaintBreach($totals, $limits)
            ?? static::unsubscribeBreach($totals, $limits);
    }

    /**
     * Why every campaign on the domain must pause, or null while its last
     * 24 hours (since its last such pause) are within the config's bounce
     * and complaint limits.
     */
    public static function domainBreach(string $domain, CarbonInterface $now): ?string
    {
        $since = $now->copy()->subHours(static::DOMAIN_HOURS);
        $campaigns = static::domainCampaigns($domain, $since);

        if ($campaigns->isEmpty()) {
            return null;
        }

        $floor = $since;

        foreach ($campaigns as $campaign) {
            if ($campaign->domain_limits_reset_at?->greaterThan($floor)) {
                $floor = $campaign->domain_limits_reset_at;
            }
        }

        $sends = CampaignMessage::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->whereNotNull('sent_at')
            ->where('sent_at', '>', $floor);

        $limits = static::thresholds();
        $totals = static::totals($sends);

        return static::bounceBreach($sends, $totals, $limits)
            ?? static::complaintBreach($totals, $limits);
    }

    /**
     * The campaign's spam reports since its limits last started again.
     */
    public static function complaints(Campaign $campaign): int
    {
        return static::campaignSends($campaign)->whereNotNull('complained_at')->count();
    }

    /**
     * The domain's campaigns that may have sent since the given time: those
     * sending or paused, and those completed or cancelled since.
     *
     * @return EloquentCollection<int, Campaign>
     */
    public static function domainCampaigns(string $domain, CarbonInterface $since): EloquentCollection
    {
        return Campaign::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused])
                ->orWhere('completed_at', '>=', $since)
                ->orWhere('canceled_at', '>=', $since))
            ->orderBy('id')
            ->get(['id', 'from_email', 'domain_limits_reset_at'])
            ->filter(fn (Campaign $campaign): bool => $campaign->sendingDomain() === $domain)
            ->values();
    }

    /**
     * @return Builder<CampaignMessage>
     */
    protected static function campaignSends(Campaign $campaign): Builder
    {
        return CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('sent_at')
            ->when($campaign->limits_reset_at, fn (Builder $query, CarbonInterface $from): Builder => $query->where('sent_at', '>', $from));
    }

    /**
     * @param  Builder<CampaignMessage>  $sends
     * @return array{sends: int, hard_bounces: int, complaints: int, unsubscribes: int}
     */
    protected static function totals(Builder $sends): array
    {
        $row = (clone $sends)->toBase()->selectRaw(
            'count(*) as sends, '
            .'sum(case when failure_reason = ? then 1 else 0 end) as hard_bounces, '
            .'sum(case when complained_at is not null then 1 else 0 end) as complaints, '
            .'sum(case when unsubscribed_at is not null then 1 else 0 end) as unsubscribes',
            [DeliveryFailure::HardBounce->value],
        )->first();

        return [
            'sends' => (int) ($row->sends ?? 0),
            'hard_bounces' => (int) ($row->hard_bounces ?? 0),
            'complaints' => (int) ($row->complaints ?? 0),
            'unsubscribes' => (int) ($row->unsubscribes ?? 0),
        ];
    }

    /**
     * The hard bounces among the last $window sends, newest first.
     *
     * @param  Builder<CampaignMessage>  $sends
     * @return array{sends: int, hard_bounces: int}
     */
    protected static function recent(Builder $sends, int $window): array
    {
        $recent = (clone $sends)
            ->select(['failure_reason'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($window);

        $row = (new CampaignMessage)->getConnection()->query()
            ->fromSub($recent, 'recent')
            ->selectRaw('count(*) as sends, sum(case when failure_reason = ? then 1 else 0 end) as hard_bounces', [DeliveryFailure::HardBounce->value])
            ->first();

        return [
            'sends' => (int) ($row->sends ?? 0),
            'hard_bounces' => (int) ($row->hard_bounces ?? 0),
        ];
    }

    /**
     * @param  Builder<CampaignMessage>  $sends
     * @param  array{sends: int, hard_bounces: int, complaints: int, unsubscribes: int}  $totals
     * @param  array<string, int|float>  $limits
     */
    protected static function bounceBreach(Builder $sends, array $totals, array $limits): ?string
    {
        ['sends' => $sent, 'hard_bounces' => $hard] = $totals;

        if ($hard === 0) {
            return null;
        }

        $earlyUntil = (int) $limits['early_until'];

        if ($sent < $earlyUntil) {
            $count = (int) $limits['early_hard_bounces'];
            $rate = (float) $limits['early_hard_bounce_rate'];

            if ($hard >= $count) {
                return static::number($hard).' of '.static::number($sent).' emails bounced hard, reaching the limit of '.static::number($count).' before '.static::number($earlyUntil).' emails (early_hard_bounces).';
            }

            if ($sent >= (int) $limits['early_min_sends'] && static::percent($hard, $sent) >= $rate) {
                return static::number($hard).' of '.static::number($sent).' emails bounced hard ('.static::percentText($hard, $sent).'), reaching the limit of '.static::rateText($rate).' (early_hard_bounce_rate).';
            }

            return null;
        }

        $recent = static::recent($sends, max(1, (int) $limits['hard_bounce_window']));
        $rate = (float) $limits['hard_bounce_rate'];

        if ($recent['hard_bounces'] > 0 && static::percent($recent['hard_bounces'], $recent['sends']) > $rate) {
            return static::number($recent['hard_bounces']).' of the last '.static::number($recent['sends']).' emails bounced hard ('.static::percentText($recent['hard_bounces'], $recent['sends']).'), past the limit of '.static::rateText($rate).' (hard_bounce_rate).';
        }

        return null;
    }

    /**
     * @param  array{sends: int, hard_bounces: int, complaints: int, unsubscribes: int}  $totals
     * @param  array<string, int|float>  $limits
     */
    protected static function complaintBreach(array $totals, array $limits): ?string
    {
        ['sends' => $sent, 'complaints' => $complaints] = $totals;

        if ($complaints === 0) {
            return null;
        }

        $until = (int) $limits['complaints_until'];

        if ($sent < $until) {
            $count = (int) $limits['complaints'];

            return $complaints >= $count
                ? static::count($complaints, 'spam report').' in '.static::number($sent).' emails, reaching the limit of '.static::number($count).' before '.static::number($until).' emails (complaints).'
                : null;
        }

        $rate = (float) $limits['complaint_rate'];

        return static::percent($complaints, $sent) > $rate
            ? static::count($complaints, 'spam report').' in '.static::number($sent).' emails ('.static::percentText($complaints, $sent).'), past the limit of '.static::rateText($rate).' (complaint_rate).'
            : null;
    }

    /**
     * @param  array{sends: int, hard_bounces: int, complaints: int, unsubscribes: int}  $totals
     * @param  array<string, int|float>  $limits
     */
    protected static function unsubscribeBreach(array $totals, array $limits): ?string
    {
        ['sends' => $sent, 'unsubscribes' => $unsubscribes] = $totals;
        $rate = (float) $limits['unsubscribe_rate'];

        return $unsubscribes > 0 && $sent >= (int) $limits['unsubscribe_min_sends'] && static::percent($unsubscribes, $sent) > $rate
            ? static::count($unsubscribes, 'unsubscribe').' in '.static::number($sent).' emails ('.static::percentText($unsubscribes, $sent).'), past the limit of '.static::rateText($rate).' (unsubscribe_rate).'
            : null;
    }

    /**
     * A share in percent. Divided, not multiplied out: the quotient of two
     * whole numbers rounds to the same float as a rate written with the same
     * value, so a share exactly at a limit (2 in 2,500 against 0.08) equals
     * it, while 0.08 times 2,500 may not come out at exactly 2.
     */
    protected static function percent(int $count, int $of): float
    {
        return $of === 0 ? 0.0 : $count * 100 / $of;
    }

    protected static function percentText(int $count, int $of): string
    {
        return static::rateText(static::percent($count, $of));
    }

    protected static function rateText(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 3, '.', ''), '0'), '.').'%';
    }

    protected static function number(int $number): string
    {
        return number_format($number);
    }

    protected static function count(int $count, string $noun): string
    {
        return static::number($count).' '.$noun.($count === 1 ? '' : 's');
    }
}
