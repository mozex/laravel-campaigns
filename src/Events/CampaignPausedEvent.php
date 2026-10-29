<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Campaign;

/**
 * A campaign was paused: by a person or an agent ($automatic false), or by
 * the package itself, such as when its bounces or complaints pass a limit
 * or the provider refuses its domain ($automatic true, with the reason).
 */
class CampaignPausedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public Campaign $campaign,
        public ?string $reason = null,
        public bool $automatic = false,
    ) {}
}
