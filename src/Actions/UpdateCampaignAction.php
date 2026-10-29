<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Support\CampaignSettings;

/**
 * Changes a draft campaign's settings: its name, From name and address (on
 * the sending domain), Reply-To, target lists (by key), audience, tracking,
 * UTM tags, pace and stop-loss thresholds. Only the settings given change.
 * Once started or scheduled, only its pace changes (ChangePaceAction).
 */
class UpdateCampaignAction
{
    /**
     * @param  array<string, mixed>  $settings  keyed by CampaignSettings::KEYS
     *
     * @throws CampaignsException|ValidationException
     */
    public function execute(Campaign $campaign, array $settings): Campaign
    {
        $columns = CampaignSettings::columns($settings, $campaign);

        return $campaign->getConnection()->transaction(function () use ($campaign, $columns): Campaign {
            // The same lock AddRevisionAction and the start take, so a change
            // can't land after the campaign is claimed for starting.
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::invalidValue('campaign', 'was deleted');

            if ($locked->status !== CampaignStatus::Draft) {
                throw CampaignsException::campaignCannot('changed', $locked->status->value);
            }

            // Pace and thresholds merge into what the locked row holds.
            $locked->forceFill(CampaignSettings::merged($columns, $locked))->save();

            return $locked;
        });
    }
}
