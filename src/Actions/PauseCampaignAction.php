<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Support\AfterCommit;
use Mozex\Campaigns\Support\TextValue;

/**
 * Pauses a sending or scheduled campaign: nothing more is handed to the
 * queue, and a message already queued goes back to waiting. Pausing a
 * paused campaign changes nothing, so stopping never fails for having been
 * done already. Works while sending is switched off, since stopping must
 * never wait.
 */
class PauseCampaignAction
{
    /**
     * @param  ?string  $reason  why, kept on the campaign (pause_reason)
     * @param  bool  $automatic  whether the package paused it itself (a limit passed, the provider refusing), as the event tells listeners
     *
     * @throws CampaignsException for a campaign that is neither sending nor scheduled
     */
    public function execute(Campaign $campaign, ?string $reason = null, bool $automatic = false): Campaign
    {
        $reason = TextValue::optional('reason', $reason, 5000);

        $paused = Campaign::query()
            ->whereKey($campaign->getKey())
            ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Scheduled])
            ->update([
                'status' => CampaignStatus::Paused,
                'paused_at' => now(),
                'pause_reason' => $reason,
                'updated_at' => now(),
            ]) === 1;

        $campaign->refresh();

        if ($paused) {
            // A listener's failure is reported, never thrown: the pause has
            // happened, and the caller must hear that it did.
            AfterCommit::dispatch($campaign->getConnection(), new CampaignPausedEvent($campaign, $reason, $automatic));
        } elseif ($campaign->status !== CampaignStatus::Paused) {
            throw CampaignsException::campaignCannot('paused', $campaign->status->value);
        }

        return $campaign;
    }
}
