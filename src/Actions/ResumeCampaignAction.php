<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Events\CampaignStartedEvent;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Support\AfterCommit;

/**
 * Resumes a paused campaign where it stopped. One paused before its
 * scheduled time that is still ahead goes back to waiting for it; one whose
 * time has passed starts now. After a pause longer than a day, the pace
 * starts again from its first day, as a campaign starting cold should.
 */
class ResumeCampaignAction
{
    /**
     * @throws CampaignsException while sending is switched off, or for a campaign that isn't paused
     */
    public function execute(Campaign $campaign): Campaign
    {
        if (! config('campaigns.enabled')) {
            throw CampaignsException::sendingDisabled();
        }

        $started = false;

        $campaign = $campaign->getConnection()->transaction(function () use ($campaign, &$started): Campaign {
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::invalidValue('campaign', 'was deleted');

            if ($locked->status !== CampaignStatus::Paused) {
                throw CampaignsException::campaignCannot('resumed', $locked->status->value);
            }

            if ($locked->started_at === null && $locked->scheduled_at?->isFuture()) {
                $locked->forceFill(['status' => CampaignStatus::Scheduled, 'pause_reason' => null])->save();

                return $locked;
            }

            $started = $locked->started_at === null;
            $restart = $started || $locked->paused_at === null || $locked->paused_at->lt(now()->subDay());

            $locked->forceFill([
                'status' => CampaignStatus::Sending,
                'pause_reason' => null,
                'started_at' => $locked->started_at ?? now(),
                'pace_started_at' => $restart ? now() : $locked->pace_started_at,
            ])->save();

            return $locked;
        });

        if ($started) {
            AfterCommit::dispatch($campaign->getConnection(), new CampaignStartedEvent($campaign));
        }

        return $campaign;
    }
}
