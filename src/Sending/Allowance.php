<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Sender;

/**
 * How many of a sending campaign's messages the pump may hand to the queue
 * now: the least of what its per-minute rate leaves this minute, what its
 * day's volume leaves, and what its sending domain's daily cap leaves, and
 * none outside its sending hours. Everything is counted from the messages
 * themselves (their claimed_at), never from cache counters, which a release
 * clears; a message that went back to pending or was skipped never reached
 * the provider, so it doesn't count.
 */
class Allowance
{
    /**
     * A pace's day: 24 hours from campaigns.pace_started_at, which a resume
     * after a long pause moves.
     */
    public const DAY = 86400;

    public static function for(Campaign $campaign, CarbonInterface $now): int
    {
        $pace = Pace::resolve($campaign->pace);

        if (! Pace::isSendingTime($pace, $now)) {
            return 0;
        }

        // Halved after the provider took nothing (a 429 or a 5xx); a rate of
        // one stays one.
        $perMinute = $campaign->rate_halved_until?->isAfter($now)
            ? (int) ceil($pace['per_minute'] / 2)
            : $pace['per_minute'];

        $started = $campaign->pace_started_at ?? $campaign->started_at ?? $now;
        $day = intdiv(max(0, $now->getTimestamp() - $started->getTimestamp()), self::DAY) + 1;
        $dayStart = $started->copy()->addSeconds(($day - 1) * self::DAY);

        $left = min(
            $perMinute - static::claimed([$campaign->id], $now->copy()->startOfMinute()),
            Pace::dayVolume($pace, $day) - static::claimed([$campaign->id], $dayStart),
            static::domainLeft($campaign, $now) ?? PHP_INT_MAX,
        );

        return max(0, $left);
    }

    /**
     * What the sending domain's daily cap leaves over the last 24 hours,
     * across every campaign on the domain and the double opt-in emails sent
     * from it; null when the domain has no cap. The cap is
     * campaigns.domain_daily_caps' entry for the domain, else the smallest
     * daily cap among the domain's sending campaigns.
     */
    public static function domainLeft(Campaign $campaign, CarbonInterface $now): ?int
    {
        // The pump asks every minute, so a domain with no cap counts nothing.
        return static::domainUsage($campaign->sendingDomain(), $now, countWithoutCap: false)['left'];
    }

    /**
     * A sending domain's daily cap and what its last 24 hours used of it:
     * the campaigns' claims and the double opt-in emails, and what is left
     * (null with no cap). Without a cap, the use is counted only when asked.
     *
     * @return array{cap: ?int, claimed: int, confirmations: int, left: ?int}
     */
    public static function domainUsage(string $domain, CarbonInterface $now, bool $countWithoutCap = true): array
    {
        $since = $now->copy()->subSeconds(self::DAY);

        // Every campaign that may have claimed in the window: one that sent
        // then has since been paused, completed or cancelled still counts.
        $onDomain = Campaign::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused])
                ->orWhere('completed_at', '>=', $since)
                ->orWhere('canceled_at', '>=', $since))
            ->get(['id', 'status', 'from_email', 'pace'])
            ->filter(fn (Campaign $other): bool => $other->sendingDomain() === $domain);

        // min() skips the campaigns with no daily cap.
        $cap = static::configuredCap($domain) ?? $onDomain
            ->filter(fn (Campaign $other): bool => $other->status === CampaignStatus::Sending)
            ->min(fn (Campaign $other): ?int => Pace::resolve($other->pace)['daily_cap']);

        if ($cap === null && ! $countWithoutCap) {
            return ['cap' => null, 'claimed' => 0, 'confirmations' => 0, 'left' => null];
        }

        $claimed = static::claimed($onDomain->modelKeys(), $since);
        $confirmations = static::confirmations($domain, $since);

        return [
            'cap' => $cap === null ? null : (int) $cap,
            'claimed' => $claimed,
            'confirmations' => $confirmations,
            'left' => $cap === null ? null : (int) $cap - $claimed - $confirmations,
        ];
    }

    /**
     * Messages of these campaigns claimed since a moment that reached the
     * provider or may yet.
     *
     * @param  array<int, int>  $campaignIds
     */
    public static function claimed(array $campaignIds, CarbonInterface $since): int
    {
        if ($campaignIds === []) {
            return 0;
        }

        return CampaignMessage::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where('claimed_at', '>=', $since)
            ->whereNotIn('status', [MessageStatus::Pending, MessageStatus::Skipped])
            ->count();
    }

    /**
     * Read as a whole map: its keys are domains, whose dots a dotted config
     * key would read as nesting.
     */
    protected static function configuredCap(string $domain): ?int
    {
        foreach ((array) config('campaigns.domain_daily_caps', []) as $key => $cap) {
            if (is_string($key) && strtolower(trim($key)) === $domain && is_numeric($cap)) {
                return (int) $cap;
            }
        }

        return null;
    }

    /**
     * The double opt-in emails go from the marketing domain, one an address
     * a day at most (campaign_subscribers.confirmation_sent_at).
     */
    protected static function confirmations(string $domain, CarbonInterface $since): int
    {
        try {
            $marketing = Sender::domain();
        } catch (CampaignsException) {
            return 0;
        }

        return $marketing === $domain
            ? Subscriber::query()->where('confirmation_sent_at', '>=', $since)->count()
            : 0;
    }
}
