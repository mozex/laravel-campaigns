<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Events\CampaignCompletedEvent;
use Mozex\Campaigns\Events\CampaignQueueStalledEvent;
use Mozex\Campaigns\Events\CampaignStartedEvent;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Sending\Allowance;
use Mozex\Campaigns\Sending\PumpResult;
use Throwable;

/**
 * One run of the pump (campaigns:pump, every minute): starts the scheduled
 * campaigns whose time came, hands back starts whose freeze was lost, and
 * for each sending campaign hands its next messages to the queue, as many as
 * its pace allows, or completes it when nothing is left. It does nothing
 * while sending is switched off.
 *
 * Messages are claimed with the run's own claim id: the pending ids are read
 * first (PostgreSQL has no UPDATE ... LIMIT), then claimed by an update that
 * takes only rows still pending, and only the rows carrying the run's id get
 * a send job. Two runs at once can read the same ids, but each row goes to
 * one of them.
 */
class PumpCampaignsAction
{
    /**
     * A queued message this old means the queue's worker isn't taking them.
     */
    public const STALLED_AFTER = 300;

    /**
     * How long past its job's timeout a start may still be freezing before
     * the pump takes its job for lost: room for the job to wait its turn.
     */
    public const STARTING_GRACE = 600;

    /**
     * The mark in campaigns.cache_store holding when the pump last ran.
     */
    public const LAST_RUN = 'campaigns:pump:last_run';

    public function execute(): PumpResult
    {
        // Before the switch, so a report tells a scheduler that stopped from
        // sending switched off. A cache value rather than a column, unlike
        // the pace's state: it only shows, and a release's flush is mended
        // by the next run, a minute later.
        rescue(fn (): bool => Cache::store(config('campaigns.cache_store'))->forever(static::LAST_RUN, now()->getTimestamp()));

        if (! config('campaigns.enabled')) {
            return new PumpResult(enabled: false);
        }

        $now = now();
        $run = (string) Str::uuid();
        $started = $this->startScheduled($now);
        $handedBack = $this->handBackLostStarts($now);
        $claimed = $stalled = $completed = [];

        foreach (Campaign::query()->where('status', CampaignStatus::Sending)->orderBy('id')->get() as $campaign) {
            // One campaign's failure (a lock wait, a broken setting) must not
            // stop the others: it is reported and tried again next minute.
            try {
                if ($this->complete($campaign, $now)) {
                    $completed[] = $campaign->id;

                    continue;
                }

                if ($this->isStalled($campaign, $now)) {
                    $stalled[] = $campaign->id;

                    continue;
                }

                $allowance = Allowance::for($campaign, $now);

                if ($allowance > 0) {
                    $claimed[$campaign->id] = count($this->claim($campaign, $this->pendingIds($campaign, $allowance), $run, $now));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new PumpResult(true, $started, $handedBack, array_filter($claimed), $stalled, $completed);
    }

    /**
     * When the pump last ran, as far as campaigns.cache_store remembers.
     */
    public static function lastRun(): ?CarbonInterface
    {
        $timestamp = rescue(fn (): mixed => Cache::store(config('campaigns.cache_store'))->get(static::LAST_RUN));

        // Redis keeps a number unserialized and gives it back as a string.
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    /**
     * The next pending messages in send order.
     *
     * @return list<int>
     */
    public function pendingIds(Campaign $campaign, int $limit): array
    {
        return CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', MessageStatus::Pending)
            ->orderBy('position')
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Claims those of the given messages still pending for this run, and
     * hands each it got to the queue. Another run may have read the same
     * ids; whichever updates a row first gets it.
     *
     * @param  list<int>  $ids
     * @return list<int> the messages this run claimed
     */
    public function claim(Campaign $campaign, array $ids, string $run, CarbonInterface $now): array
    {
        if ($ids === []) {
            return [];
        }

        CampaignMessage::query()
            ->whereIn('id', $ids)
            ->where('campaign_id', $campaign->id)
            ->where('status', MessageStatus::Pending)
            ->update([
                'status' => MessageStatus::Queued,
                'claim_id' => $run,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]);

        $claimed = CampaignMessage::query()
            ->where('status', MessageStatus::Queued)
            ->where('claim_id', $run)
            ->where('campaign_id', $campaign->id)
            ->orderBy('position')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($claimed as $id) {
            SendMessageJob::dispatch($id, $run);
        }

        return $claimed;
    }

    /**
     * @return list<int>
     */
    protected function startScheduled(CarbonInterface $now): array
    {
        $started = [];

        $due = Campaign::query()
            ->where('status', CampaignStatus::Scheduled)
            ->where('scheduled_at', '<=', $now)
            ->pluck('id');

        foreach ($due as $id) {
            try {
                $updated = Campaign::query()
                    ->whereKey($id)
                    ->where('status', CampaignStatus::Scheduled)
                    ->where('scheduled_at', '<=', $now)
                    ->update([
                        'status' => CampaignStatus::Sending,
                        'started_at' => $now,
                        'pace_started_at' => $now,
                        'updated_at' => $now,
                    ]);

                if ($updated === 1) {
                    $started[] = (int) $id;
                    CampaignStartedEvent::dispatch(Campaign::query()->findOrFail($id));
                }
            } catch (Throwable $exception) {
                // A listener that fails (an alert mail) must not keep the
                // other campaigns from starting or sending.
                report($exception);
            }
        }

        return $started;
    }

    /**
     * A freeze job lost without running (a queue flushed, a worker gone
     * before it began) never reaches its own failed(), so its campaign would
     * stay starting for good. Nothing but a cancel writes a starting
     * campaign, so its updated_at is when the start claimed it.
     *
     * @return list<int>
     */
    protected function handBackLostStarts(CarbonInterface $now): array
    {
        $handedBack = [];
        $reason = 'Its start stopped before the audience was frozen (its job never ran, or never finished). Start it again.';

        $lost = Campaign::query()
            ->where('status', CampaignStatus::Starting)
            ->where('updated_at', '<', $now->copy()->subSeconds(FreezeCampaignJob::TIMEOUT + self::STARTING_GRACE))
            ->get(['id', 'start_run']);

        foreach ($lost as $campaign) {
            try {
                if (FreezeCampaignAction::refuse($campaign, $reason, (string) $campaign->start_run)) {
                    $handedBack[] = $campaign->id;
                }
            } catch (Throwable $exception) {
                // A freeze still holding the row (a lock wait timeout).
                report($exception);
            }
        }

        return $handedBack;
    }

    /**
     * Completes a campaign none of whose messages is still waiting or on its
     * way, in one conditional update, so a pause landing at the same moment
     * is never overwritten.
     */
    protected function complete(Campaign $campaign, CarbonInterface $now): bool
    {
        $completed = Campaign::query()
            ->whereKey($campaign->id)
            ->where('status', CampaignStatus::Sending)
            ->whereDoesntHave('messages', fn (Builder $query): Builder => $query
                ->whereIn('status', [MessageStatus::Pending, MessageStatus::Queued, MessageStatus::Sending]))
            ->update([
                'status' => CampaignStatus::Completed,
                'completed_at' => $now,
                'updated_at' => $now,
            ]) === 1;

        // Found again rather than refreshed: a model from a list of several
        // refuses lazy loading under strict models, which a listener reading
        // its relations would hit.
        if ($completed) {
            CampaignCompletedEvent::dispatch(Campaign::query()->findOrFail($campaign->id));
        }

        return $completed;
    }

    /**
     * Messages queued minutes ago and never picked up: claiming more would
     * only pile them up, so none are claimed until these move (the
     * reconciler returns them to pending once they are older still).
     */
    protected function isStalled(Campaign $campaign, CarbonInterface $now): bool
    {
        $stalled = CampaignMessage::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', MessageStatus::Queued)
            ->where('claimed_at', '<', $now->copy()->subSeconds(self::STALLED_AFTER));

        $oldest = (clone $stalled)->orderBy('claimed_at')->first(['id', 'claimed_at']);

        if ($oldest?->claimed_at === null) {
            return false;
        }

        CampaignQueueStalledEvent::dispatch(Campaign::query()->findOrFail($campaign->id), $stalled->count(), $oldest->claimed_at);

        return true;
    }
}
