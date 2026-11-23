<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\Usage;

/**
 * A driver whose service says what the account has sent against its
 * allowance (a plan's monthly volume, or a rolling quota), which the
 * overview and the start, resume and speed-up answers show an agent judging
 * whether a campaign fits.
 */
interface ReadsUsage
{
    /**
     * What the account has sent so far in its current period, and what the
     * period allows.
     *
     * @throws CampaignsException
     */
    public function usage(): Usage;
}
