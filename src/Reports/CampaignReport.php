<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\UnsubscribeAction;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MailboxProvider;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;

/**
 * A campaign's numbers, in counts only (no address ever appears): its
 * messages by status; its sends (the emails the provider took), deliveries,
 * failures by kind, hard bounces, spam reports and unsubscribes (split by
 * how: a mail app's one-click, the page, the provider's own link); the
 * people who opened and clicked, with every human and machine open and click
 * the provider reported, and the clicks per link; the rates, spam reports
 * and unsubscribes per send as the stop-loss measures them, opens and clicks
 * per delivery, machines left out; the same numbers by list, language,
 * engagement tier (as it stood at the start) and mailbox provider; a
 * timeline by day in the app's timezone; and the queue as it stands now.
 *
 * It is worked out from the messages and the provider's events each time it
 * is read, since a campaign's numbers keep moving after it completes (late
 * bounces, opens, the reconciler), until FreezeReportAction keeps it on the
 * campaign, before the events it counts are pruned. The queue is always now.
 */
class CampaignReport
{
    /**
     * The most links listed, the most clicked by people first.
     */
    public const LINKS = 100;

    /**
     * Messages and events read at a time.
     */
    public const CHUNK = 1000;

    /**
     * Where a message's address is gone with its person.
     */
    public const UNKNOWN_PROVIDER = 'unknown';

    /**
     * A breakdown's row: how many of its messages did each thing.
     */
    protected const ROW = [
        'messages' => 0,
        'sends' => 0,
        'delivered' => 0,
        'hard_bounces' => 0,
        'sender_blocked' => 0,
        'complaints' => 0,
        'unsubscribes' => 0,
        'opened' => 0,
        'clicked' => 0,
    ];

    /**
     * What happened on a day, by when each message's step happened.
     */
    protected const DAY = [
        'sent' => 0,
        'delivered' => 0,
        'failed' => 0,
        'hard_bounces' => 0,
        'opened' => 0,
        'clicked' => 0,
        'complaints' => 0,
        'unsubscribes' => 0,
    ];

    /**
     * The report an agent reads: the frozen one when there is one, else
     * worked out now; its queue always now.
     *
     * @return array<string, mixed>
     */
    public static function for(Campaign $campaign): array
    {
        return [...($campaign->report ?? static::build($campaign)), 'queue' => static::queue($campaign)];
    }

    /**
     * The report worked out now, without the queue.
     *
     * @return array<string, mixed>
     */
    public static function build(Campaign $campaign): array
    {
        $statuses = array_fill_keys(array_column(MessageStatus::cases(), 'value'), 0);
        $totals = [...static::ROW, 'unsent' => 0];
        $failures = [];
        $byList = [];
        $byLocale = [];
        $byTier = array_fill_keys(array_column(EngagementTier::cases(), 'value'), static::ROW);
        $byProvider = array_fill_keys([...array_column(MailboxProvider::cases(), 'value'), static::UNKNOWN_PROVIDER], static::ROW);
        $timeline = [];
        $unsubscribed = [];
        $lists = CampaignList::query()->pluck('key', 'id');
        $tiers = EngagementTier::cases();
        $days = static::days();

        // Plain rows, not models: hydrating each message and its dates
        // outlasts a request's time limit once a campaign reaches hundreds
        // of thousands.
        $messages = CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->toBase()
            ->select(['id', 'uuid', 'list_ids', 'email', 'locale', 'position', 'status', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'unsubscribed_at', 'complained_at', 'failed_at', 'failure_reason'])
            ->lazyById(static::CHUNK, 'id');

        foreach ($messages as $message) {
            $status = (string) $message->status;
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $row = static::row($message);

            static::add($totals, $row);
            $totals['unsent'] += (int) in_array($status, [MessageStatus::Pending->value, MessageStatus::Queued->value], true);

            if ($status === MessageStatus::Failed->value) {
                $reason = $message->failure_reason ?? DeliveryFailure::Other->value;
                $failures[$reason] = ($failures[$reason] ?? 0) + 1;
            }

            foreach (static::listIds($message->list_ids) as $listId) {
                $key = (string) ($lists[$listId] ?? "#{$listId}");
                $byList[$key] ??= static::ROW;
                static::add($byList[$key], $row);
            }

            $locale = $message->locale ?? 'none';
            $byLocale[$locale] ??= static::ROW;
            static::add($byLocale[$locale], $row);

            $tier = ($tiers[intdiv((int) $message->position, FreezeCampaignAction::TIER_STEP)] ?? EngagementTier::Silent)->value;
            static::add($byTier[$tier], $row);

            $provider = $message->email === null ? static::UNKNOWN_PROVIDER : MailboxProvider::fromEmail((string) $message->email)->value;
            static::add($byProvider[$provider], $row);

            $timeline = static::timeline($timeline, $message, $row, $days);

            if ($message->unsubscribed_at !== null) {
                $unsubscribed[] = (string) $message->uuid;
            }
        }

        ksort($failures);
        ksort($byList);
        ksort($byLocale);
        ksort($timeline);

        [$opens, $clicks, $links, $linksTotal] = static::events($campaign);

        return [
            'campaign' => [
                'key' => $campaign->key,
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'recipients' => $campaign->recipient_count,
                'started_at' => $campaign->started_at?->toIso8601String(),
                'completed_at' => $campaign->completed_at?->toIso8601String(),
                'canceled_at' => $campaign->canceled_at?->toIso8601String(),
            ],
            'messages' => $totals['messages'],
            'statuses' => $statuses,
            'sends' => $totals['sends'],
            'unsent' => $totals['unsent'],
            'delivered' => $totals['delivered'],
            'failures' => $failures,
            'hard_bounces' => $totals['hard_bounces'],
            'complaints' => $totals['complaints'],
            'unsubscribes' => ['total' => $totals['unsubscribes'], ...static::unsubscribes($unsubscribed)],
            'opens' => ['people' => $totals['opened'], ...$opens],
            'clicks' => ['people' => $totals['clicked'], ...$clicks],
            'rates' => [
                'delivery' => static::rate($totals['delivered'], $totals['sends']),
                'hard_bounce' => static::rate($totals['hard_bounces'], $totals['sends']),
                'complaint' => static::rate($totals['complaints'], $totals['sends']),
                'unsubscribe' => static::rate($totals['unsubscribes'], $totals['sends']),
                'open' => static::rate($totals['opened'], $totals['delivered']),
                'click' => static::rate($totals['clicked'], $totals['delivered']),
            ],
            'links' => $links,
            'links_total' => $linksTotal,
            'by_list' => $byList,
            'by_locale' => $byLocale,
            'by_tier' => $byTier,
            'by_provider' => $byProvider,
            'timeline' => $timeline,
            'computed_at' => now()->toIso8601String(),
            'frozen_at' => null,
        ];
    }

    /**
     * Where the campaign's sending stands now: when the pump last ran (as
     * campaigns.cache_store remembers it), what is left to send, what waits
     * on the queue and since when, and how many jobs wait on the package's
     * queue in all (null when its connection can't tell).
     *
     * @return array{pump_last_run: ?string, pending: int, queued: int, stalled: int, oldest_queued_at: ?string, jobs_waiting: ?int}
     */
    public static function queue(Campaign $campaign): array
    {
        $messages = CampaignMessage::query()->where('campaign_id', $campaign->id);
        $queued = (clone $messages)->where('status', MessageStatus::Queued);

        $jobs = rescue(fn (): int => Queue::connection(config('campaigns.queue.connection'))
            ->size((string) config('campaigns.queue.name')));

        return [
            'pump_last_run' => PumpCampaignsAction::lastRun()?->toIso8601String(),
            'pending' => (clone $messages)->where('status', MessageStatus::Pending)->count(),
            'queued' => (clone $queued)->count(),
            'stalled' => (clone $queued)->where('claimed_at', '<', now()->subSeconds(PumpCampaignsAction::STALLED_AFTER))->count(),
            'oldest_queued_at' => (clone $queued)->orderBy('claimed_at')->first(['id', 'claimed_at'])?->claimed_at?->toIso8601String(),
            'jobs_waiting' => is_int($jobs) ? $jobs : null,
        ];
    }

    /**
     * What one message did, from its plain row.
     *
     * @return array<string, int>
     */
    protected static function row(object $message): array
    {
        return [
            'messages' => 1,
            'sends' => (int) ($message->sent_at !== null),
            'delivered' => (int) ($message->status === MessageStatus::Delivered->value),
            'hard_bounces' => (int) ($message->failure_reason === DeliveryFailure::HardBounce->value),
            'sender_blocked' => (int) ($message->failure_reason === DeliveryFailure::SenderBlocked->value),
            'complaints' => (int) ($message->complained_at !== null),
            'unsubscribes' => (int) ($message->unsubscribed_at !== null),
            'opened' => (int) ($message->opened_at !== null),
            'clicked' => (int) ($message->clicked_at !== null),
        ];
    }

    /**
     * @param  array<string, int>  $into
     * @param  array<string, int>  $row
     */
    protected static function add(array &$into, array $row): void
    {
        foreach ($row as $key => $count) {
            $into[$key] += $count;
        }
    }

    /**
     * The list ids a plain row stores as JSON.
     *
     * @return list<int>
     */
    protected static function listIds(mixed $stored): array
    {
        $ids = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($ids) ? array_values(array_map(fn (mixed $id): int => (int) $id, $ids)) : [];
    }

    /**
     * The day a stored time falls on in the app's timezone. Eloquent reads a
     * stored time in PHP's default timezone, which Laravel sets from
     * app.timezone, so the stored date is already that day; the time is
     * converted only when the two differ.
     *
     * @return Closure(string): string
     */
    protected static function days(): Closure
    {
        $zone = (string) config('app.timezone', 'UTC');

        if ($zone === date_default_timezone_get()) {
            return fn (string $at): string => substr($at, 0, 10);
        }

        return fn (string $at): string => Date::parse($at)->setTimezone($zone)->toDateString();
    }

    /**
     * @param  array<string, array<string, int>>  $timeline
     * @param  array<string, int>  $row
     * @param  Closure(string): string  $days
     * @return array<string, array<string, int>>
     */
    protected static function timeline(array $timeline, object $message, array $row, Closure $days): array
    {
        $steps = [
            'sent' => $message->sent_at,
            'delivered' => $message->delivered_at,
            'failed' => $message->failed_at,
            'hard_bounces' => $row['hard_bounces'] === 1 ? $message->failed_at : null,
            'opened' => $message->opened_at,
            'clicked' => $message->clicked_at,
            'complaints' => $message->complained_at,
            'unsubscribes' => $message->unsubscribed_at,
        ];

        foreach ($steps as $step => $at) {
            if ($at !== null) {
                $day = $days((string) $at);
                $timeline[$day] ??= static::DAY;
                $timeline[$day][$step]++;
            }
        }

        return $timeline;
    }

    /**
     * Every open and click the provider reported about the campaign's
     * messages, a person's or a machine's, the clicks per link (the most
     * clicked LINKS), and how many links were clicked in all.
     *
     * @return array{0: array{human: int, machine: int}, 1: array{human: int, machine: int}, 2: list<array{url: string, human: int, machine: int}>, 3: int}
     */
    protected static function events(Campaign $campaign): array
    {
        $messages = CampaignMessage::query()->select('id')->where('campaign_id', $campaign->id);

        $opens = ['human' => 0, 'machine' => 0];

        $counted = ProviderEvent::query()
            ->where('type', FeedbackType::Opened)
            ->whereIn('message_id', $messages)
            ->toBase()
            ->selectRaw('is_bot, count(*) as total')
            ->groupBy('is_bot')
            ->get();

        foreach ($counted as $row) {
            $opens[(bool) $row->is_bot ? 'machine' : 'human'] += (int) $row->total;
        }

        $clicks = ['human' => 0, 'machine' => 0];
        $links = [];

        $events = ProviderEvent::query()
            ->where('type', FeedbackType::Clicked)
            ->whereIn('message_id', $messages)
            ->select(['id', 'is_bot', 'payload'])
            ->lazyById(static::CHUNK);

        foreach ($events as $event) {
            $who = $event->is_bot ? 'machine' : 'human';
            $url = $event->payload['url'] ?? null;
            $url = is_string($url) && $url !== '' ? $url : 'unknown';

            $clicks[$who]++;
            $links[$url] ??= ['url' => $url, 'human' => 0, 'machine' => 0];
            $links[$url][$who]++;
        }

        usort($links, fn (array $a, array $b): int => [$b['human'], $b['machine'], $a['url']] <=> [$a['human'], $a['machine'], $b['url']]);

        return [$opens, $clicks, array_slice($links, 0, static::LINKS), count($links)];
    }

    /**
     * How the unsubscribes came, read from the consent record each one wrote
     * (its opt-out names the message), which outlives a forgotten person; an
     * unsubscribe with no such record (an import's) is "other".
     *
     * @param  list<string>  $uuids
     * @return array{one_click: int, page: int, provider: int, other: int}
     */
    protected static function unsubscribes(array $uuids): array
    {
        $sources = [
            UnsubscribeAction::ONE_CLICK => 'one_click',
            UnsubscribeAction::PAGE => 'page',
            UnsubscribeAction::PROVIDER => 'provider',
        ];

        $how = [];

        foreach (array_chunk($uuids, 500) as $chunk) {
            $records = ConsentRecord::query()
                ->where('action', ConsentAction::OptOut)
                ->whereIn('source', array_keys($sources))
                ->whereIn('meta->message', $chunk)
                ->orderBy('id')
                ->get(['id', 'source', 'meta']);

            foreach ($records as $record) {
                $uuid = $record->meta['message'] ?? null;

                if (is_string($uuid) && ! isset($how[$uuid])) {
                    $how[$uuid] = $sources[(string) $record->source];
                }
            }
        }

        $counts = array_count_values($how);

        return [
            'one_click' => $counts['one_click'] ?? 0,
            'page' => $counts['page'] ?? 0,
            'provider' => $counts['provider'] ?? 0,
            'other' => count($uuids) - count($how),
        ];
    }

    /**
     * A share in percent to three places, or null when there is nothing to
     * share out yet.
     */
    protected static function rate(int $count, int $of): ?float
    {
        return $of === 0 ? null : round($count * 100 / $of, 3);
    }
}
