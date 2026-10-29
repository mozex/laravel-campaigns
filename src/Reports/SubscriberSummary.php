<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\Engagement;

/**
 * A subscriber as an agent reads it: a short row for a page of them, and
 * the whole of one with its lists and the latest entries of its consent
 * ledger.
 *
 * Each is cut so the longest stays under what an MCP client reads in one
 * answer, counted as JSON, where a quote or backslash takes two characters:
 * free text is shown with its control characters as spaces (one would take
 * six), a row cuts its names to NAME_LENGTH and names at most ROW_LISTS
 * lists and no custom fields, and one person whole shows DETAIL_LISTS
 * lists, the latest LEDGER_ENTRIES consent records with their meta cut to
 * META_LENGTH, and custom field values cut to FIELD_LENGTH. The tests build
 * both at their longest.
 *
 * The ledger leaves out the IP address and browser a change was made from:
 * they are the person's own data, kept on the server to answer a complaint.
 */
class SubscriberSummary
{
    public const NAME_LENGTH = 100;

    public const ROW_LISTS = 5;

    public const DETAIL_LISTS = 20;

    public const LEDGER_ENTRIES = 10;

    public const FIELD_LENGTH = 80;

    public const META_LENGTH = 150;

    /**
     * Rows for a page of subscribers, with their subscriptions loaded.
     *
     * @param  iterable<Subscriber>  $subscribers
     * @return list<array<string, mixed>>
     */
    public static function rows(iterable $subscribers): array
    {
        $subscribers = collect($subscribers)->values();
        $keys = static::listKeys($subscribers->flatMap(fn (Subscriber $subscriber): Collection => $subscriber->subscriptions->pluck('list_id'))->all());

        return $subscribers->map(fn (Subscriber $subscriber): array => [
            ...static::basics($subscriber, static::NAME_LENGTH),
            'lists' => $subscriber->subscriptions
                ->sortBy('list_id')
                ->take(static::ROW_LISTS)
                ->map(fn (Subscription $subscription): array => [
                    'key' => $keys[$subscription->list_id] ?? null,
                    'status' => $subscription->status->value,
                ])
                ->values()
                ->all(),
            'lists_total' => $subscriber->subscriptions->count(),
            'custom_fields_count' => count($subscriber->custom_fields ?? []),
        ])->all();
    }

    /**
     * One subscriber whole: details, engagement, the lists they are on
     * with how they joined (up to DETAIL_LISTS), custom fields and the
     * latest consent records (up to LEDGER_ENTRIES), newest first.
     *
     * @return array<string, mixed>
     */
    public static function detail(Subscriber $subscriber): array
    {
        $subscriptions = $subscriber->subscriptions()->orderBy('list_id')->limit(static::DETAIL_LISTS)->get();
        $records = $subscriber->consentRecords()->orderByDesc('occurred_at')->orderByDesc('id')->limit(static::LEDGER_ENTRIES)->get();
        $keys = static::listKeys([...$subscriptions->pluck('list_id')->all(), ...$records->pluck('list_id')->filter()->all()]);

        return [
            ...static::basics($subscriber),
            'timezone' => static::shown($subscriber->timezone),
            'soft_bounces' => $subscriber->soft_bounces,
            'last_opened_at' => static::date($subscriber->last_opened_at),
            'last_clicked_at' => static::date($subscriber->last_clicked_at),
            'last_active_at' => static::date($subscriber->last_active_at),
            'confirmation_sent_at' => static::date($subscriber->confirmation_sent_at),
            'custom_fields' => static::customFields($subscriber),
            'lists' => $subscriptions->map(fn (Subscription $subscription): array => [
                'key' => $keys[$subscription->list_id] ?? null,
                'status' => $subscription->status->value,
                'basis' => $subscription->basis->value,
                'source' => static::shown($subscription->source),
                'subscribed_at' => static::date($subscription->subscribed_at),
                'confirmed_at' => static::date($subscription->confirmed_at),
            ])->all(),
            'lists_total' => $subscriber->subscriptions()->count(),
            'ledger' => $records->map(fn (ConsentRecord $record): array => [
                'action' => $record->action->value,
                'list' => $record->list_id === null ? null : $keys[$record->list_id] ?? null,
                'basis' => $record->basis?->value,
                'source' => static::shown($record->source),
                'notice_version' => static::shown($record->notice_version),
                'occurred_at' => static::date($record->occurred_at),
                'meta' => $record->meta === null ? null : static::shown((string) json_encode($record->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), static::META_LENGTH),
            ])->all(),
            'ledger_total' => $subscriber->consentRecords()->count(),
        ];
    }

    /**
     * The subscriber's custom fields, a text value cut to FIELD_LENGTH
     * characters (one stored as anything else shown as JSON, cut the same).
     *
     * @return array<string, mixed>
     */
    public static function customFields(Subscriber $subscriber): array
    {
        return array_map(
            fn (mixed $value): mixed => match (true) {
                is_string($value) => static::shown($value, static::FIELD_LENGTH),
                $value === null || is_scalar($value) => $value,
                default => static::shown((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), static::FIELD_LENGTH),
            },
            $subscriber->custom_fields ?? [],
        );
    }

    /**
     * Whether the subscriber may be mailed, and why not.
     *
     * @return array{email: string, mailable: bool, opted_out_at: ?string, blocked_at: ?string, blocked_reason: ?string}
     */
    public static function state(Subscriber $subscriber): array
    {
        return [
            'email' => $subscriber->email,
            'mailable' => $subscriber->isMailable(),
            'opted_out_at' => static::date($subscriber->opted_out_at),
            'blocked_at' => static::date($subscriber->blocked_at),
            'blocked_reason' => $subscriber->blocked_reason?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function basics(Subscriber $subscriber, ?int $nameLength = null): array
    {
        return [
            'email' => $subscriber->email,
            'name' => static::shown($subscriber->name, $nameLength),
            'first_name' => static::shown($subscriber->first_name, $nameLength),
            'locale' => $subscriber->locale,
            'country' => $subscriber->country,
            'linked' => $subscriber->subscribable_type !== null,
            'tier' => $subscriber->tier()->value,
            'sunset' => $subscriber->isSunset(),
            'mailable' => $subscriber->isMailable(),
            'opted_out_at' => static::date($subscriber->opted_out_at),
            'blocked_at' => static::date($subscriber->blocked_at),
            'blocked_reason' => $subscriber->blocked_reason?->value,
            'last_engaged_at' => static::date(Engagement::lastEngagedAt($subscriber)),
            'first_mailed_at' => static::date($subscriber->first_mailed_at),
            'created_at' => static::date($subscriber->created_at),
        ];
    }

    /**
     * Free text as shown: its control characters as spaces, cut to a length
     * when one is given.
     */
    protected static function shown(?string $value, ?int $length = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/\p{Cc}/u', ' ', $value);

        return $length === null ? $value : Str::limit($value, $length);
    }

    /**
     * @param  array<array-key, int>  $ids
     * @return array<int, string>
     */
    protected static function listKeys(array $ids): array
    {
        /** @var array<int, string> */
        return $ids === [] ? [] : CampaignList::query()->whereIn('id', array_unique($ids))->pluck('key', 'id')->all();
    }

    protected static function date(?CarbonInterface $date): ?string
    {
        return $date?->toIso8601String();
    }
}
