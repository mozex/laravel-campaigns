<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Models\ProviderEvent;

/**
 * Applies one stored provider event's effects, once: the event's row is
 * locked and marked processed in the same transaction as the effects, so a
 * duplicate job, or a retry after the effects landed, changes nothing.
 */
class ProcessFeedbackJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

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

    public function handle(): void
    {
        (new ProviderEvent)->getConnection()->transaction(function (): void {
            $event = ProviderEvent::query()->lockForUpdate()->find($this->eventId);

            if ($event === null || $event->processed_at !== null) {
                return;
            }

            $event->forceFill(['processed_at' => now()])->save();
        });
    }
}
