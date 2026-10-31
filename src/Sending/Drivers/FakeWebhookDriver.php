<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;

/**
 * A fake driver with webhooks and suppression lists but no logs or counts,
 * as a service that posts its events and keeps no searchable record of them.
 */
class FakeWebhookDriver implements CampaignDriver, ReadsSuppressions, ReceivesWebhooks
{
    use FakesDriver;
}
