<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Reports\CampaignReport;

/**
 * Keeps a completed or cancelled campaign's report on the campaign
 * (campaigns.report, with frozen_at), so it never changes again: run before
 * the provider's events it counts are pruned. Until then a report is worked
 * out when read, since a campaign's numbers keep moving after it completes.
 * A report already frozen stays as it is.
 */
class FreezeReportAction
{
    /**
     * Returns whether it froze the report now.
     *
     * @throws CampaignsException for a campaign whose numbers can still move on their own (sending, paused)
     */
    public function execute(Campaign $campaign): bool
    {
        if (! in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Canceled], true)) {
            throw CampaignsException::reportStillMoving($campaign->status->value);
        }

        $report = [...CampaignReport::build($campaign), 'frozen_at' => now()->toIso8601String()];

        return Campaign::query()
            ->whereKey($campaign->id)
            ->whereNull('report')
            ->whereIn('status', [CampaignStatus::Completed, CampaignStatus::Canceled])
            ->update(['report' => json_encode($report, JSON_THROW_ON_ERROR), 'updated_at' => now()]) === 1;
    }
}
