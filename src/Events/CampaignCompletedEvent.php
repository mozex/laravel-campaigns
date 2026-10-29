<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Campaign;

/**
 * A campaign has nothing left to send: every message was sent, skipped or
 * failed, or is unknown. The provider may still report deliveries, bounces
 * and opens for its messages after this.
 */
class CampaignCompletedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Campaign $campaign) {}
}
