<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Support\Links;
use Mozex\Campaigns\Support\ShownText;

/**
 * Campaigns as the tools show them: a row in a list, or the whole of one
 * with its current email. A stored audience may name 10,000 subscribers or
 * records, so it shows how many, never which; its filters and a named
 * audience's parameters show as JSON text, cut.
 */
class CampaignSummary
{
    /** How much of a name a row shows. */
    public const NAME_LENGTH = 100;

    /** How many target lists a row names, and one campaign's answer. */
    public const ROW_LISTS = 5;

    public const DETAIL_LISTS = 50;

    /** How much of an audience's filters, or a named audience's parameters, as JSON. */
    public const AUDIENCE_TEXT_LENGTH = 1000;

    /** How much of a start's error or a pause's reason. */
    public const REASON_LENGTH = 300;

    /**
     * A page of campaigns, their lists' keys read in one query.
     *
     * @param  iterable<Campaign>  $campaigns
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $campaigns): array
    {
        $campaigns = collect($campaigns);
        $keys = static::listKeys($campaigns->flatMap(fn (Campaign $campaign): array => $campaign->target_lists ?? [])->all());

        return $campaigns->map(fn (Campaign $campaign): array => static::row($campaign, $keys))->values()->all();
    }

    /**
     * @param  array<int, string>  $listKeys  list keys by id
     * @return array<string, mixed>
     */
    public static function row(Campaign $campaign, array $listKeys, int $lists = self::ROW_LISTS): array
    {
        return [
            'uuid' => $campaign->uuid,
            'key' => $campaign->key,
            'name' => ShownText::of($campaign->name, static::NAME_LENGTH),
            'status' => $campaign->status->value,
            'from_email' => $campaign->from_email,
            'lists' => array_slice(static::lists($campaign, $listKeys), 0, $lists),
            'lists_total' => count($campaign->target_lists ?? []),
            'recipient_count' => $campaign->recipient_count,
            'created_at' => $campaign->created_at?->toIso8601String(),
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'started_at' => $campaign->started_at?->toIso8601String(),
            'completed_at' => $campaign->completed_at?->toIso8601String(),
            'canceled_at' => $campaign->canceled_at?->toIso8601String(),
        ];
    }

    /**
     * One campaign whole: its settings, its audience in counts, where it
     * came from, and its current email with links to download it.
     *
     * @return array<string, mixed>
     */
    public static function detail(Campaign $campaign): array
    {
        $campaign->loadMissing(['template:id,key', 'duplicatedFrom:id,key']);
        $revision = $campaign->currentRevision()->with(['contents', 'frozenFrom:id,number'])->first();

        return [
            ...static::row($campaign, static::listKeys($campaign->target_lists ?? []), static::DETAIL_LISTS),
            'name' => ShownText::of($campaign->name),
            'from_name' => ShownText::of($campaign->from_name),
            'reply_to' => $campaign->reply_to,
            'track_opens' => $campaign->track_opens,
            'track_clicks' => $campaign->track_clicks,
            'utm' => (object) array_map(fn (string $tag): string => (string) ShownText::of($tag), $campaign->utmTags()),
            'pace' => (object) ($campaign->pace ?? []),
            'thresholds' => (object) ($campaign->thresholds ?? []),
            'audience' => static::audience($campaign->audience),
            'template' => $campaign->template?->key,
            'duplicated_from' => $campaign->duplicatedFrom?->key,
            'start_error' => ShownText::of($campaign->start_error, static::REASON_LENGTH),
            'paused_at' => $campaign->paused_at?->toIso8601String(),
            'pause_reason' => ShownText::of($campaign->pause_reason, static::REASON_LENGTH),
            'revisions' => $campaign->revisions()->count(),
            'revision' => $revision === null ? null : RevisionSummary::detail($revision, Links::downloadsExpireAt()),
        ];
    }

    /**
     * A stored audience (AudienceDefinition::toArray()) in what it names and
     * how many, never the ids themselves.
     *
     * @param  ?array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function audience(?array $stored): array
    {
        $named = is_array($stored['audience'] ?? null) ? $stored['audience'] : null;
        $subscribers = $stored['subscribers'] ?? null;

        return [
            'audience' => is_string($named['name'] ?? null) ? $named['name'] : null,
            'parameters' => static::jsonText($named['parameters'] ?? null),
            'saved_audience' => is_string($stored['saved_audience'] ?? null) ? $stored['saved_audience'] : null,
            'filters' => static::jsonText($stored['filters'] ?? null),
            'subscribers' => is_array($subscribers) ? count($subscribers) : null,
            'records' => (object) array_map(fn (mixed $ids): int => is_array($ids) ? count($ids) : 0, (array) ($stored['records'] ?? [])),
            'unmatched_emails' => (int) ($stored['unmatched_emails'] ?? 0),
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, string>
     */
    protected static function listKeys(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return CampaignList::query()->whereIn('id', array_values(array_unique($ids)))->pluck('key', 'id')->all();
    }

    /**
     * @param  array<int, string>  $listKeys
     * @return list<string>
     */
    protected static function lists(Campaign $campaign, array $listKeys): array
    {
        $keys = array_map(fn (int|string $id): ?string => $listKeys[(int) $id] ?? null, $campaign->target_lists ?? []);

        return array_values(array_filter($keys, 'is_string'));
    }

    /**
     * Filters or parameters as JSON text, cut. Their texts are shown as
     * ShownText shows any, before encoding: JSON would write a control
     * character as an escape the answer's own encoding doubles.
     */
    public static function jsonText(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (is_array($value)) {
            array_walk_recursive($value, function (mixed &$leaf): void {
                $leaf = is_string($leaf) ? ShownText::of($leaf) : $leaf;
            });
        }

        return ShownText::of((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), static::AUDIENCE_TEXT_LENGTH);
    }
}
