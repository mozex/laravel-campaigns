<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Support\ShownText;
use Throwable;

/**
 * How each list fills and when it was last used, as the lists tool shows
 * them: the records a dynamic list follows, the joins of the last
 * JOIN_DAYS days by where they came from, the last join, and the last
 * campaign that started with the list among its targets.
 *
 * A join is a subscribe consent record on the list. A record that leaves a
 * dynamic list and comes back writes none, so it isn't one. The joins are
 * grouped in the database from the index on (list_id, action, occurred_at,
 * source), which holds every column they read, so their cost follows the
 * joins of the last JOIN_DAYS days, never the history before; the last join
 * is one index entry a list. The last campaign is read per list with a limit
 * of one, over the campaigns, which stay few. Sources are ranked in PHP,
 * since the databases order NULLs and text differently.
 */
class ListActivity
{
    /** How far back the joins are counted. */
    public const JOIN_DAYS = 30;

    /** How many sources a list shows; the rest are counted as other. */
    public const SOURCES = 10;

    /** How much of a source, or of a dynamic list's record type, shows. */
    public const SOURCE_LENGTH = 60;

    public const RECORDS_LENGTH = 100;

    /**
     * @param  Collection<int, CampaignList>  $lists
     * @return array<int, array<string, mixed>> by list id
     */
    public static function of(Collection $lists): array
    {
        if ($lists->isEmpty()) {
            return [];
        }

        $joins = static::joins($lists->map(fn (CampaignList $list): int => $list->id)->values()->all());

        return $lists->mapWithKeys(fn (CampaignList $list): array => [$list->id => [
            'records' => static::records($list),
            'recent_joins' => $joins[$list->id] ?? ['total' => 0, 'by_source' => [], 'other' => 0],
            'last_joined_at' => static::lastJoin($list),
            'last_campaign' => static::lastCampaign($list),
        ]])->all();
    }

    /**
     * The recent joins of each list, by source, the largest first.
     *
     * @param  list<int>  $ids
     * @return array<int, array{total: int, by_source: list<array{source: ?string, count: int}>, other: int}>
     */
    protected static function joins(array $ids): array
    {
        $rows = ConsentRecord::query()->toBase()
            ->whereIn('list_id', $ids)
            ->where('action', ConsentAction::Subscribe->value)
            ->where('occurred_at', '>=', now()->subDays(static::JOIN_DAYS))
            ->groupBy('list_id', 'source')
            ->selectRaw('list_id, source, count(*) as total')
            ->get();

        $joins = [];

        foreach ($rows->groupBy(fn (object $row): int => (int) $row->list_id) as $id => $sources) {
            $ranked = $sources
                ->map(fn (object $row): array => ['source' => is_string($row->source) ? $row->source : null, 'count' => (int) $row->total])
                ->sort(fn (array $a, array $b): int => [$b['count'], $a['source'] === null, (string) $a['source']] <=> [$a['count'], $b['source'] === null, (string) $b['source']])
                ->values();

            $shown = $ranked->take(static::SOURCES);

            $joins[$id] = [
                'total' => (int) $ranked->sum('count'),
                'by_source' => $shown->map(fn (array $source): array => [
                    'source' => ShownText::of($source['source'], static::SOURCE_LENGTH),
                    'count' => $source['count'],
                ])->all(),
                'other' => (int) $ranked->skip(static::SOURCES)->sum('count'),
            ];
        }

        return $joins;
    }

    /**
     * The type of the app's records a dynamic list follows, as its morph
     * map names it. Null for a static list, and for a dynamic list whose
     * source is gone from the config; a source the config names but that
     * can't be built is reported.
     */
    protected static function records(CampaignList $list): ?string
    {
        if ($list->kind !== ListKind::Dynamic || ! array_key_exists($list->key, (array) config('campaigns.list_sources', []))) {
            return null;
        }

        try {
            $type = app(Campaigns::class)->listSource($list->key)->query()->getModel()->getMorphClass();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return ShownText::of($type, static::RECORDS_LENGTH);
    }

    protected static function lastJoin(CampaignList $list): ?string
    {
        $at = ConsentRecord::query()->toBase()
            ->where('list_id', $list->id)
            ->where('action', ConsentAction::Subscribe->value)
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        return is_string($at) ? Date::parse($at)->toIso8601String() : null;
    }

    /**
     * The latest campaign by start that had the list among its target
     * lists, which hold the lists' ids as numbers.
     *
     * @return ?array{key: string, started_at: string}
     */
    protected static function lastCampaign(CampaignList $list): ?array
    {
        $campaign = Campaign::query()->toBase()
            ->whereNotNull('started_at')
            ->whereJsonContains('target_lists', $list->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first(['key', 'started_at']);

        if ($campaign === null) {
            return null;
        }

        return [
            'key' => (string) $campaign->key,
            'started_at' => Date::parse((string) $campaign->started_at)->toIso8601String(),
        ];
    }
}
