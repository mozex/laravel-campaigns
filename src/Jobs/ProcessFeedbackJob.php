<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Actions\ApplyFeedbackAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Models\ProviderEvent;

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
     * How long a click waits before it is applied, in seconds, so the other
     * clicks of a link scanner's burst are stored by then and the first one
     * is recognised too.
     */
    public const CLICK_DELAY = 60;

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
     * Queues a stored event's job, a click's a little later. Not named
     * queue(): Laravel's dispatcher calls a job's queue() method to push it.
     */
    public static function dispatchFor(ProviderEvent $event): void
    {
        $pending = static::dispatch($event->id);

        if ($event->type === FeedbackType::Clicked) {
            $pending->delay(static::CLICK_DELAY);
        }
    }

    public function handle(ApplyFeedbackAction $apply): void
    {
        (new ProviderEvent)->getConnection()->transaction(function () use ($apply): void {
            $event = ProviderEvent::query()->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            $apply->execute($event);

            $event->forceFill(['processed_at' => now()])->save();
        });
    }
}
