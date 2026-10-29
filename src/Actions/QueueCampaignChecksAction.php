<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Models\Campaign;

/**
 * Queues the pre-send checks for a campaign's current email, for the
 * languages its recipients read and with its own click tracking. Their
 * results stay on the revision for whoever reads them before a start, and a
 * start carries them onto the copy it freezes; nothing fetches again.
 */
class QueueCampaignChecksAction
{
    public function __construct(protected QueueChecksAction $queueChecks) {}

    /**
     * @throws CampaignsException
     */
    public function execute(Campaign $campaign): string
    {
        $revision = $campaign->currentRevision()->first() ?? throw CampaignsException::noRevision();
        $locales = $campaign->target_lists ? CampaignAudience::for($campaign)->locales() : null;

        return $this->queueChecks->execute($revision, $locales, $campaign->track_clicks);
    }
}
