<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Campaign;

/**
 * A campaign began sending: started at once, resumed before its scheduled
 * start ever came, or reached its scheduled time.
 */
class CampaignStartedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Campaign $campaign) {}
}
