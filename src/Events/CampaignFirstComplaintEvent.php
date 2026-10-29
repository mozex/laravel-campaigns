<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Campaign;

/**
 * A campaign got its first spam report (since its limits last started
 * again). It keeps sending: the stop-loss pauses it only once more follow,
 * so this is the early warning.
 */
class CampaignFirstComplaintEvent
{
    use Dispatchable;

    public function __construct(public Campaign $campaign) {}
}
