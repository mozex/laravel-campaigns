<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Engagement;

/**
 * Lists with their counts, as the overview and the list tools show them:
 * subscriptions by status, how many subscribed people may be mailed (not
 * opted out, blocked or sunset), and subscribed people by engagement tier.
 * A few grouped queries, whatever the number of lists.
 */
class ListSummary
{
    /**
     * How much of a description a row shows, so a page of lists stays short.
     */
    public const DESCRIPTION_LENGTH = 300;

    /**
     * @param  iterable<CampaignList>  $lists
     * @param  bool  $details  Add each list's description (cut to DESCRIPTION_LENGTH), its default basis, and when it was made and archived.
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $lists, bool $details = false): array
    {
        $lists = collect($lists)->values();

        if ($lists->isEmpty()) {
            return [];
        }

        $ids = $lists->map(fn (CampaignList $list): int => $list->id)->all();

        $statuses = Subscription::query()
            ->whereIn('list_id', $ids)
            ->groupBy('list_id', 'status')
            ->selectRaw('list_id, status, count(*) as total')
            ->toBase()
            ->get();

        $mailable = static::subscribedCounts($ids, fn (Builder $query): Builder => $query->mailable());

        $tiers = [];

        foreach (EngagementTier::cases() as $tier) {
            $tiers[$tier->value] = static::subscribedCounts($ids, fn (Builder $query): Builder => Engagement::whereTier($query, $tier));
        }

        return $lists->map(function (CampaignList $list) use ($statuses, $mailable, $tiers, $details): array {
            $counts = [];

            foreach (SubscriptionStatus::cases() as $status) {
                $counts[$status->value] = (int) ($statuses->first(fn (object $row): bool => (int) $row->list_id === $list->id && $row->status === $status->value)->total ?? 0);
            }

            return [
                'key' => $list->key,
                'name' => $list->name,
                ...$details ? ['description' => $list->description === null ? null : Str::limit($list->description, static::DESCRIPTION_LENGTH)] : [],
                'kind' => $list->kind->value,
                'double_opt_in' => $list->double_opt_in,
                ...$details ? ['default_basis' => $list->default_basis->value] : [],
                ...$counts,
                'mailable' => (int) ($mailable[$list->id] ?? 0),
                'tiers' => array_map(fn (Collection $tierCounts): int => (int) ($tierCounts[$list->id] ?? 0), $tiers),
                ...$details ? [
                    'created_at' => $list->created_at?->toIso8601String(),
                    'archived_at' => $list->archived_at?->toIso8601String(),
                ] : [],
            ];
        })->all();
    }

    /**
     * The subscribed people on each list whose subscriber the constraint
     * keeps, by list id.
     *
     * @param  list<int>  $ids
     * @param  callable(Builder<Subscriber>): Builder<Subscriber>  $constraint
     * @return Collection<int, int>
     */
    protected static function subscribedCounts(array $ids, callable $constraint): Collection
    {
        /** @var Collection<int, int> */
        return Subscription::query()
            ->whereIn('list_id', $ids)
            ->where('status', SubscriptionStatus::Subscribed)
            ->whereHas('subscriber', $constraint)
            ->groupBy('list_id')
            ->selectRaw('list_id, count(*) as total')
            ->toBase()
            ->pluck('total', 'list_id');
    }
}
