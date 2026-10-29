<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Models\Subscriber;

/**
 * The engagement tiers and the sunset, defined once for a subscriber in
 * hand and once for a query, side by side so the two can't drift.
 *
 * Nothing is stored: a tier or a sunset follows from the timestamps, so a
 * human open, a click or app activity (Campaigns::recordActivity()) moves
 * someone up a tier and ends a sunset the moment it is recorded.
 *
 * The queries never combine the timestamps with GREATEST() or a NOT: MySQL's
 * GREATEST is NULL when any argument is, PostgreSQL's ignores NULLs, and NOT
 * over a NULL comparison drops the row. Each column is compared on its own.
 */
class Engagement
{
    /**
     * The columns that show the person read the app's email or used the app.
     * Apple Mail's automatic opens never reach last_opened_at.
     */
    public const SIGNALS = ['last_opened_at', 'last_clicked_at', 'last_active_at'];

    public static function lastEngagedAt(Subscriber $subscriber): ?CarbonInterface
    {
        $dates = array_filter(array_map(fn (string $column): ?CarbonInterface => $subscriber->getAttribute($column), static::SIGNALS));

        return $dates === [] ? null : max($dates);
    }

    public static function tier(Subscriber $subscriber): EngagementTier
    {
        return static::tiers()($subscriber);
    }

    /**
     * The tier of each subscriber a long pass reads (a freeze), with the
     * cutoffs worked out once: working them out per subscriber cost more
     * than everything else in the tier.
     *
     * @return Closure(Subscriber): EngagementTier
     */
    public static function tiers(): Closure
    {
        $engaged = static::engagedCutoff();
        $active = static::activeCutoff();

        return function (Subscriber $subscriber) use ($engaged, $active): EngagementTier {
            $last = static::lastEngagedAt($subscriber);

            return match (true) {
                $last !== null && $last->gte($engaged) => EngagementTier::Engaged,
                $last !== null && $last->gte($active) => EngagementTier::Active,
                default => EngagementTier::Silent,
            };
        };
    }

    /**
     * Whether the address has stayed silent for the sunset period since the
     * later of its last engagement and its first campaign email. An address
     * this app never mailed is never sunset, so a list brought in from
     * elsewhere still gets the whole period.
     */
    public static function isSunset(Subscriber $subscriber): bool
    {
        $cutoff = static::sunsetCutoff();

        if ($cutoff === null || $subscriber->first_mailed_at === null || $subscriber->first_mailed_at->gte($cutoff)) {
            return false;
        }

        $last = static::lastEngagedAt($subscriber);

        return $last === null || $last->lt($cutoff);
    }

    /**
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereTier(Builder $query, EngagementTier $tier): Builder
    {
        return match ($tier) {
            EngagementTier::Engaged => static::whereEngagedSince($query, static::engagedCutoff()),
            EngagementTier::Active => static::whereSilentSince(static::whereEngagedSince($query, static::activeCutoff()), static::engagedCutoff()),
            EngagementTier::Silent => static::whereSilentSince($query, static::activeCutoff()),
        };
    }

    /**
     * Keeps the subscribers that are sunset, or, given false, the ones that
     * aren't.
     *
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereSunset(Builder $query, bool $sunset = true): Builder
    {
        $cutoff = static::sunsetCutoff();

        if ($cutoff === null) {
            return $sunset ? $query->whereRaw('1 = 0') : $query;
        }

        if ($sunset) {
            return static::whereSilentSince($query->where('first_mailed_at', '<', $cutoff), $cutoff);
        }

        return $query->where(function (Builder $query) use ($cutoff): void {
            $query->whereNull('first_mailed_at')->orWhere('first_mailed_at', '>=', $cutoff);

            foreach (static::SIGNALS as $column) {
                $query->orWhere($column, '>=', $cutoff);
            }
        });
    }

    /**
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected static function whereEngagedSince(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where(function (Builder $query) use ($cutoff): void {
            foreach (static::SIGNALS as $column) {
                $query->orWhere($column, '>=', $cutoff);
            }
        });
    }

    /**
     * @template TBuilder of Builder<Subscriber>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    protected static function whereSilentSince(Builder $query, CarbonInterface $cutoff): Builder
    {
        foreach (static::SIGNALS as $column) {
            $query->where(fn (Builder $query): Builder => $query->whereNull($column)->orWhere($column, '<', $cutoff));
        }

        return $query;
    }

    protected static function engagedCutoff(): CarbonInterface
    {
        return now()->startOfSecond()->subDays((int) config('campaigns.engagement.engaged_days', 90));
    }

    protected static function activeCutoff(): CarbonInterface
    {
        return now()->startOfSecond()->subDays((int) config('campaigns.engagement.active_days', 365));
    }

    /**
     * Stored timestamps have whole seconds, so the cutoffs drop their
     * fraction too, or the database and PHP would disagree at the boundary.
     */
    protected static function sunsetCutoff(): ?CarbonInterface
    {
        $months = config('campaigns.engagement.sunset_months', 12);

        return $months === null ? null : now()->startOfSecond()->subMonthsNoOverflow((int) $months);
    }
}
