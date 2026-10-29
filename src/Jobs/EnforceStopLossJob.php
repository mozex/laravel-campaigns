<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\Actions\EnforceStopLossAction;
use Mozex\Campaigns\Sending\AppliedFeedback;

/**
 * The stop-loss's check after someone unsubscribed from a campaign's email
 * through its link or their mail app's one-click, queued rather than run in
 * that request: the one-click comes from Gmail's and Yahoo's servers, and a
 * pause would send its alert mail while they wait. One waits per campaign at
 * a time, since it measures every unsubscribe made by then; it stops
 * holding that place once it starts, so one made while it runs is checked
 * again.
 */
class EnforceStopLossJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * How long a waiting check holds its campaign's place, in seconds, so a
     * job lost from the queue doesn't hold it for good.
     */
    public int $uniqueFor = 600;

    public function __construct(public int $campaignId)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function uniqueId(): string
    {
        return (string) $this->campaignId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(config('campaigns.cache_store'));
    }

    public function handle(EnforceStopLossAction $stopLoss): void
    {
        $stopLoss->execute(new AppliedFeedback($this->campaignId, unsubscribed: true));
    }
}
