<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;

/**
 * Cancels a campaign for good, in any status but completed: nothing more of
 * it is sent. Its messages stay as they are, the ones never sent still
 * pending, so its numbers show how far it got. A start still freezing finds
 * it cancelled and leaves nothing behind. Cancelling a cancelled campaign
 * changes nothing; it works while sending is switched off.
 */
class CancelCampaignAction
{
    /**
     * @throws CampaignsException for a completed campaign
     */
    public function execute(Campaign $campaign): Campaign
    {
        Campaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [CampaignStatus::Draft, CampaignStatus::Starting, CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused])
            ->update([
                'status' => CampaignStatus::Canceled,
                'canceled_at' => now(),
                'updated_at' => now(),
            ]);

        $campaign->refresh();

        if ($campaign->status !== CampaignStatus::Canceled) {
            throw CampaignsException::campaignCannot('cancelled', $campaign->status->value);
        }

        return $campaign;
    }
}
