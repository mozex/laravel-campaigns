<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Mozex\Campaigns\Contracts\CampaignDriver;

/**
 * A fake driver that only sends, as a plain SMTP relay does: no webhooks,
 * logs, suppression lists or counts, so a test sees what the package does
 * without them.
 */
class FakeSendOnlyDriver implements CampaignDriver
{
    use FakesDriver;
}
