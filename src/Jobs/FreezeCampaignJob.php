<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
use Mozex\Campaigns\Models\Campaign;
use Throwable;

/**
 * Freezes a campaign a start or schedule claimed. Queued, since reading a
 * large audience outlasts an agent's tool call, which returns at once and
 * reads the campaign's status after. It carries its start's run, so a job
 * left over from an earlier start touches nothing.
 */
class FreezeCampaignJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /**
     * Give the queue's worker a longer timeout than this.
     */
    public int $timeout = 300;

    public function __construct(public int $campaignId, public int $expectedRecipients, public string $run)
    {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function handle(FreezeCampaignAction $freeze): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign !== null) {
            $freeze->execute($campaign, $this->expectedRecipients, $this->run);
        }
    }

    /**
     * A job that timed out or whose worker died never reaches the action's
     * own refusal, so the campaign is handed back here.
     */
    public function failed(?Throwable $exception = null): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign !== null) {
            FreezeCampaignAction::refuse($campaign, 'Its start stopped before the audience was frozen (it ran out of time, or its worker stopped). Start it again.', $this->run);
        }
    }
}
