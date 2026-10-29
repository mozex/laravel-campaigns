<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Sending\Pace;

/**
 * Changes a campaign's pace before or while it sends. A slow-down
 * ($faster false) refuses any change that sends faster in any way (Pace::
 * isFaster()), so the slower path can never stand in for a speed-up, which
 * an app may guard more closely and which is refused while sending is
 * switched off.
 */
class ChangePaceAction
{
    /**
     * @param  array<string, mixed>  $pace  the settings to change; the rest stay
     *
     * @throws CampaignsException
     */
    public function execute(Campaign $campaign, array $pace, bool $faster): Campaign
    {
        if ($faster && ! config('campaigns.enabled')) {
            throw CampaignsException::sendingDisabled();
        }

        $pace = Pace::validate($pace);

        return $campaign->getConnection()->transaction(function () use ($campaign, $pace, $faster): Campaign {
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::invalidValue('campaign', 'was deleted');

            if (! in_array($locked->status, [CampaignStatus::Draft, CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true)) {
                throw CampaignsException::campaignCannot('given a new pace', $locked->status->value);
            }

            $new = [...$locked->pace ?? [], ...$pace];

            if (! $faster && Pace::isFaster(Pace::resolve($locked->pace), Pace::resolve($new))) {
                throw CampaignsException::paceFaster();
            }

            $locked->forceFill(['pace' => $new])->save();

            return $locked;
        });
    }
}
