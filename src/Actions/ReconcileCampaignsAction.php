<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\ReconcileResult;
use Throwable;

/**
 * Mends what the send jobs and webhooks left unsettled, every 15 minutes:
 * queued messages whose job was lost go back to pending; events stored but
 * never processed are queued again; the service's logs are read for every
 * domain with a campaign that may still get events, from where the last run
 * stopped, and each event no webhook brought is stored and applied as a
 * webhook's would be (deliveries a service never retries, anything a webhook
 * outage missed); and each message whose send ended unclear is looked up by
 * its uuid: its events, when the logs hold any, mark it sent, and one the
 * logs hold nothing for an hour after it went to the service fails as never
 * taken, and is never sent again. Every write is conditional, so a send job
 * or an event landing at the same moment wins or loses cleanly.
 *
 * The logs are read through each configured driver that reads them
 * (ReadsEvents), for the messages it sent, with its own reach, so a switch
 * of drivers keeps reading the old one's. A driver without logs, or no
 * longer configured, is skipped, which is no problem to report.
 */
class ReconcileCampaignsAction
{
    /**
     * How long an unclear send waits before it is looked up, and an event
     * before it is queued again, in seconds.
     */
    public const LOOK_AFTER = 900;

    /**
     * How long after an unclear message went to the service an empty
     * lookup fails it, in seconds: well past the logs' half hour of delay.
     */
    public const LOST_AFTER = 3600;

    /**
     * Logs younger than this may still be filling in (a service's events can
     * reach its logs out of order), so the replay stops short of them, in
     * seconds.
     */
    public const TRUSTED_AFTER = 1800;

    /**
     * How far back an unprocessed event is queued again, in seconds, so one
     * that fails every try isn't retried for ever.
     */
    public const REQUEUE_WITHIN = 86400;

    public const REQUEUE_LIMIT = 1000;

    /**
     * The most unclear messages looked up in one run.
     */
    public const LOOKUPS = 100;

    /**
     * The most events read from one driver's logs of a domain in a run, and
     * the most stored across domains and drivers (each queues a job on the
     * campaigns queue, ahead of the send jobs), so a long outage is caught up
     * over a few runs.
     */
    public const REPLAY_READ = 5000;

    public const REPLAY_STORED = 1000;

    /**
     * How long a run keeps calling the services, in seconds, kept under its
     * schedule's 20-minute overlap lock.
     */
    public const RUN_SECONDS = 600;

    /**
     * The cache mark that limits the report of an unreadable service (no
     * key, a refused key) to one an hour.
     */
    public const PROBLEM_MARK = 'campaigns:reconcile-unreadable';

    /**
     * How many events are checked for being stored already in one query.
     */
    protected const BATCH = 100;

    protected float $deadline = 0;

    protected int $stored = 0;

    /** @var list<string> */
    protected array $problems = [];

    public function __construct(
        protected DriverManager $drivers,
        protected RecordFeedbackAction $record,
    ) {}

    /**
     * Each step, and each domain and message within one, in its own try, so
     * one failure stops only what depends on it.
     */
    public function execute(): ReconcileResult
    {
        $this->deadline = microtime(true) + static::RUN_SECONDS;
        $this->stored = 0;
        $this->problems = [];

        $released = $this->attempt(fn (): int => $this->releaseQueued()) ?? 0;
        $requeued = $this->attempt(fn (): int => $this->requeueUnprocessed()) ?? 0;
        $readers = $this->readers();
        $this->attempt(fn () => $this->replay($readers));
        [$found, $lost] = $this->lookUp($readers);

        return new ReconcileResult($released, $requeued, $this->stored, $found, $lost, array_values(array_unique($this->problems)));
    }

    /**
     * Queued messages whose claim is past the send job's own limit: a job
     * that ran would have taken them by then or put them back, so theirs was
     * lost (a dispatch that failed mid-run, a flushed queue). A job still
     * waiting can't take one any more, since it takes only a fresh claim.
     */
    protected function releaseQueued(): int
    {
        return CampaignMessage::query()
            ->where('status', MessageStatus::Queued)
            ->where('claimed_at', '<', now()->subSeconds(SendMessageJob::CLAIM_EXPIRES))
            ->update([
                'status' => MessageStatus::Pending,
                ...CampaignMessage::UNCLAIMED,
                'updated_at' => now(),
            ]);
    }

    /**
     * Events stored at least LOOK_AFTER ago and still unprocessed: their job
     * never ran (the queue was down at the insert) or every try failed. The
     * job applies an event once, whoever queues it.
     */
    protected function requeueUnprocessed(): int
    {
        $events = ProviderEvent::query()
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subSeconds(static::LOOK_AFTER))
            ->where('created_at', '>=', now()->subSeconds(static::REQUEUE_WITHIN))
            ->orderBy('id')
            ->limit(static::REQUEUE_LIMIT)
            ->get(['id', 'type', 'processed_at']);

        foreach ($events as $event) {
            ProcessFeedbackJob::dispatchFor($event);
        }

        return $events->count();
    }

    /**
     * The configured drivers that read logs, by name. One whose creator
     * fails is a problem, and the others are still read.
     *
     * @return array<string, ReadsEvents>
     */
    protected function readers(): array
    {
        $readers = [];

        foreach ($this->drivers->names() as $name) {
            $driver = $this->attempt(fn () => $this->drivers->configured($name));

            if ($driver instanceof ReadsEvents) {
                $readers[$name] = $driver;
            }
        }

        return $readers;
    }

    /**
     * The logs of every domain with a campaign that may still get events:
     * one sending or paused, or finished within the longest reach of the
     * drivers' logs.
     *
     * @param  array<string, ReadsEvents>  $readers
     */
    protected function replay(array $readers): void
    {
        if ($readers === []) {
            return;
        }

        $reach = now()->subDays(max(array_map(fn (ReadsEvents $reader): int => $reader->logDays(), $readers)));

        $campaigns = Campaign::query()
            ->whereNotNull('started_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Paused])
                ->orWhere('completed_at', '>=', $reach)
                ->orWhere('canceled_at', '>=', $reach))
            ->get(['id', 'from_email', 'started_at', 'events_read_until']);

        foreach ($campaigns->groupBy(fn (Campaign $campaign): string => $campaign->sendingDomain()) as $domain => $group) {
            if ($this->outOfTime() || $this->stored >= static::REPLAY_STORED) {
                return;
            }

            $this->attempt(fn () => $this->replayDomain((string) $domain, $group, $readers));
        }
    }

    /**
     * One domain's logs from where its campaigns were last read (a new
     * campaign from just before it started) to TRUSTED_AFTER ago, oldest
     * first, through each driver their messages went through. Where the run
     * stops, there the next one starts: past every event it stored, so an
     * outage caught up over several runs reads each page about once. With
     * two drivers' messages (a switch mid-campaign), the bookmark moves only
     * as far as the one read least far, and only once each was read.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @param  array<string, ReadsEvents>  $readers
     */
    protected function replayDomain(string $domain, Collection $campaigns, array $readers): void
    {
        /** @var CarbonInterface $from */
        $from = $campaigns
            ->map(fn (Campaign $campaign): CarbonInterface => $campaign->events_read_until ?? $campaign->started_at?->copy()->subMinutes(5) ?? now())
            ->min();

        $to = now()->subSeconds(static::TRUSTED_AFTER);

        if ($from->greaterThanOrEqualTo($to)) {
            return;
        }

        // Only the drivers these campaigns' messages went through.
        $readers = array_filter($readers, fn (string $name): bool => CampaignMessage::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->where('driver', $name)
            ->exists(), ARRAY_FILTER_USE_KEY);

        /** @var array<string, ?CarbonInterface> $reached */
        $reached = array_fill_keys(array_keys($readers), null);

        foreach ($readers as $name => $reader) {
            if ($this->stored >= static::REPLAY_STORED || $this->outOfTime()) {
                break;
            }

            // Each driver on its own, so one whose logs fail, or that reaches
            // its own read limit, leaves the others read.
            $this->attempt(function () use ($name, $reader, $domain, $from, $to, &$reached): void {
                $this->replayDriver($name, $reader, $domain, $from, $to, $reached[$name]);
            });
        }

        // A driver that wasn't read holds the bookmark where it is, so none
        // of its events is skipped; its failure is the run's problem to
        // report.
        $least = in_array(null, $reached, true) ? null : min([...$reached, $to]);

        if ($least !== null) {
            // In the app's timezone, as the database's timestamps are stored;
            // and not through Eloquent, which would touch updated_at: this is
            // the reconciler's bookmark, not a change to the campaign.
            $bookmark = Carbon::instance($least)->setTimezone((string) config('app.timezone'));

            Campaign::query()
                ->whereKey($campaigns->modelKeys())
                ->where(fn (Builder $query): Builder => $query->whereNull('events_read_until')->orWhere('events_read_until', '<', $bookmark))
                ->toBase()
                ->update(['events_read_until' => $bookmark]);
        }
    }

    /**
     * One driver's events for a domain in a period, stored a batch at a
     * time: $reached follows the last batch stored (kept when the read fails
     * part way), and is $to once every event was read.
     */
    protected function replayDriver(string $name, ReadsEvents $reader, string $domain, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface &$reached): void
    {
        $read = 0;
        $batch = [];

        foreach ($reader->eventsBetween($domain, $from, $to) as $feedback) {
            $batch[] = $feedback;

            if (count($batch) < static::BATCH) {
                continue;
            }

            $reached = $this->storeNew($batch, $name);
            $read += count($batch);
            $batch = [];

            if ($read >= static::REPLAY_READ || $this->stored >= static::REPLAY_STORED || $this->outOfTime()) {
                return;
            }
        }

        if ($batch !== []) {
            $this->storeNew($batch, $name);
        }

        $reached = $to;
    }

    /**
     * Records the events of a batch no webhook brought: the ones already
     * stored are found with one query, so a replay of a domain whose
     * webhooks work costs a read per hundred events. Returns the time of the
     * batch's last event.
     *
     * @param  non-empty-list<Feedback>  $batch
     */
    protected function storeNew(array $batch, string $driver): CarbonInterface
    {
        $known = ProviderEvent::query()
            ->whereIn('provider_event_id', array_map(fn (Feedback $feedback): string => $feedback->providerEventId, $batch))
            ->where('driver', $driver)
            ->pluck('provider_event_id')
            ->flip();

        foreach ($batch as $feedback) {
            if (! $known->has($feedback->providerEventId) && $this->record->execute($feedback, $driver) === FeedbackResult::Stored) {
                $this->stored++;
            }
        }

        return $batch[count($batch) - 1]->occurredAt;
    }

    /**
     * Messages whose send ended unclear (`sending` with its job gone, or
     * `unknown`) at least LOOK_AFTER ago, oldest first, looked up by their
     * uuid through the driver that sent them, while its logs surely reach
     * them (an hour inside their reach, so a lookup never starts after a
     * message's first events). Older ones stay unknown: the logs can no
     * longer show whether they went, and they must never go twice; a
     * `sending` one becomes `unknown`, which lets its campaign complete.
     *
     * @param  array<string, ReadsEvents>  $readers
     * @return array{int, int} how many were found, and how many failed as never taken
     */
    protected function lookUp(array $readers): array
    {
        $found = $lost = 0;

        foreach ($readers as $name => $reader) {
            [$driverFound, $driverLost] = $this->attempt(fn (): array => $this->lookUpDriver($name, $reader)) ?? [0, 0];
            $found += $driverFound;
            $lost += $driverLost;
        }

        return [$found, $lost];
    }

    /**
     * One driver's unclear messages. A service that can't be read stops its
     * lookups, since the next ones would fail alike.
     *
     * @return array{int, int}
     */
    protected function lookUpDriver(string $name, ReadsEvents $reader): array
    {
        $found = $lost = 0;
        $reach = now()->subDays($reader->logDays())->addHour();

        CampaignMessage::query()
            ->where('status', MessageStatus::Sending)
            ->where('driver', $name)
            ->where('sending_at', '<', $reach)
            ->update(['status' => MessageStatus::Unknown, 'failure_reason' => SendFailure::Unclear->value, 'updated_at' => now()]);

        $messages = CampaignMessage::query()
            ->whereIn('status', [MessageStatus::Sending, MessageStatus::Unknown])
            ->where('driver', $name)
            ->where('sending_at', '<', now()->subSeconds(static::LOOK_AFTER))
            ->where('sending_at', '>=', $reach)
            ->orderBy('sending_at')
            ->orderBy('id')
            ->limit(static::LOOKUPS)
            ->get(['id', 'uuid', 'sending_at']);

        foreach ($messages as $message) {
            if ($this->outOfTime()) {
                break;
            }

            /** @var CarbonInterface $sendingAt */
            $sendingAt = $message->sending_at;
            $events = $reader->eventsForMessage($message->uuid, $sendingAt->copy()->subMinutes(5));

            // The logs held more than a lookup reads: nothing can be told.
            if ($events === null) {
                continue;
            }

            match ($this->attempt(fn (): ?string => $this->settle($message, $sendingAt, $events, $name))) {
                'found' => $found++,
                'lost' => $lost++,
                default => null,
            };
        }

        return [$found, $lost];
    }

    /**
     * What one unclear message's lookup settles: its events, when the logs
     * hold any, are stored, and their effects mark it sent (any event proves
     * the service took it), as a webhook's would; with none an hour after
     * it went, it fails as never taken, but only while no event is stored
     * for it either: one a webhook brought that is still waiting for its job
     * would otherwise lose to the failure for good, since nothing leaves
     * failed.
     *
     * @param  list<Feedback>  $events
     * @return ?string "found", "lost", or null while it waits
     */
    protected function settle(CampaignMessage $message, CarbonInterface $sendingAt, array $events, string $driver): ?string
    {
        if ($events !== []) {
            foreach ($events as $feedback) {
                $this->record->execute($feedback, $driver);
            }

            return 'found';
        }

        if ($sendingAt->greaterThanOrEqualTo(now()->subSeconds(static::LOST_AFTER))) {
            return null;
        }

        $failed = CampaignMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', [MessageStatus::Sending, MessageStatus::Unknown])
            ->whereDoesntHave('events')
            ->update([
                'status' => MessageStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => SendFailure::NotAccepted->value,
                'updated_at' => now(),
            ]);

        return $failed === 1 ? 'lost' : null;
    }

    /**
     * Runs a step, keeping what it returns, or noting its failure as a
     * problem and returning null.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $step
     * @return ?TResult
     */
    protected function attempt(Closure $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $this->problems[] = $this->problem($exception);

            return null;
        }
    }

    protected function outOfTime(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    /**
     * A service that can't be read (no key, a refused key, an outage) is
     * reported at most once an hour, since the run comes back every 15
     * minutes; anything else is reported each time, as a bug.
     */
    protected function problem(Throwable $exception): string
    {
        if (! $exception instanceof CampaignsException) {
            report($exception);

            return 'Reconciling failed: '.class_basename($exception).'.';
        }

        $store = config('campaigns.cache_store') ?: null;

        // A cache that can't be reached reports the problem anyway.
        if (rescue(fn (): bool => Cache::store(is_string($store) ? $store : null)->add(static::PROBLEM_MARK, true, 3600), true, false)) {
            report($exception);
        }

        return $exception->getMessage();
    }
}
