<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Contracts\Drivers\ReadsMetrics;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;

/**
 * A driver that sends nothing, for tests and local runs, with every
 * ability (FakesDriver says what it records). Select it with
 * campaigns.driver "fake"; the manager keeps the one it builds, so a test
 * reads what was sent from it.
 */
class FakeDriver implements CampaignDriver, ReadsEvents, ReadsMetrics, ReadsSuppressions, ReceivesWebhooks
{
    use FakesDriver;
}
