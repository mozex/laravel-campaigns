<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Actions\ApplyFeedbackAction;
use Mozex\Campaigns\Actions\EnforceStopLossAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Sending\AppliedFeedback;
use Mozex\Campaigns\Support\DatabaseErrors;

/**
 * Applies one stored provider event's effects, once: the event's row is
 * locked and marked processed in the same transaction as the effects, so a
 * duplicate job, or a retry after the effects landed, changes nothing. An
 * effect that fails (a deadlock) rolls back with the mark, and the job tries
 * again.
 */
class ProcessFeedbackJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * How long an open or a click waits before it is applied, in seconds:
     * the other clicks of a link scanner's burst are stored by then, so the
     * first one is recognised too, and the email's delivery has had time to
     * land, which the timing rules count from.
     */
    public const ENGAGEMENT_DELAY = 60;

    /**
     * Safe to retry: nothing is applied twice.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 300];

    public function __construct(public int $eventId)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
        $this->afterCommit();
    }

    /**
     * Queues a stored event's job, an open's or a click's a little later.
     * Not named queue(): Laravel's dispatcher calls a job's queue() method
     * to push it.
     */
    public static function dispatchFor(ProviderEvent $event): void
    {
        $pending = static::dispatch($event->id);

        if ($event->type === FeedbackType::Opened || $event->type === FeedbackType::Clicked) {
            $pending->delay(static::ENGAGEMENT_DELAY);
        }
    }

    /**
     * The stop-loss runs after the commit, outside the effects' transaction,
     * so the pause's event and its listeners never share their commit with
     * the effects' own events, and a listener's failure can't fail the job
     * once the event is processed.
     */
    public function handle(ApplyFeedbackAction $apply, EnforceStopLossAction $stopLoss): void
    {
        // An effect can insert the address an event is about (DatabaseErrors).
        $applied = DatabaseErrors::scrubbed('a provider event\'s effects', fn (): ?AppliedFeedback => (new ProviderEvent)->getConnection()->transaction(function () use ($apply): ?AppliedFeedback {
            $event = ProviderEvent::query()->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return null;
            }

            $applied = $apply->execute($event);

            $event->forceFill(['processed_at' => now()])->save();

            return $applied;
        }));

        if ($applied !== null) {
            $stopLoss->execute($applied);
        }
    }
}
