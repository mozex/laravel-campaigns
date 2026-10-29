<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Campaign;

/**
 * Messages the pump handed to the queue minutes ago haven't been picked up,
 * as when the queue's worker is stopped, so the pump hands the campaign
 * nothing more until they move. Fired on every pump run (each minute) while
 * it lasts; a listener that alerts someone decides how often.
 */
class CampaignQueueStalledEvent
{
    use Dispatchable;

    public function __construct(
        public Campaign $campaign,
        public int $stalledMessages,
        // An interface, since an app may make its dates immutable.
        public CarbonInterface $oldestClaimedAt,
    ) {}
}
