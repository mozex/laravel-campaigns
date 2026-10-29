<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Support\Keys;
use Mozex\Campaigns\Support\TextValue;

/**
 * A new draft with another campaign's settings, audience and email, in any
 * status the other is in. The email is its current revision as its author
 * made it: for a started campaign, never the copy its start made with UTM
 * tags, whose links would keep the old campaign's tags for good.
 */
class DuplicateCampaignAction
{
    public function __construct(protected AddRevisionAction $addRevision) {}

    /**
     * @throws CampaignsException
     */
    public function execute(Campaign $source, string $key, string $name, ?string $author = null): Campaign
    {
        if (! Keys::isValid($key)) {
            throw CampaignsException::invalidKey($key);
        }

        $name = TextValue::required('name', $name, 255);
        $revision = $source->currentRevision()->first()?->authored() ?? throw CampaignsException::noRevision();

        return $source->getConnection()->transaction(function () use ($source, $key, $name, $revision, $author): Campaign {
            if (Campaign::query()->where('key', $key)->exists()) {
                throw CampaignsException::keyTaken('campaign', $key);
            }

            try {
                $campaign = Campaign::create([
                    ...$source->only([
                        'from_name', 'from_email', 'reply_to', 'track_opens', 'track_clicks', 'utm',
                        'target_lists', 'audience', 'pace', 'thresholds', 'template_id',
                    ]),
                    'key' => $key,
                    'name' => $name,
                    'status' => CampaignStatus::Draft,
                    'tag' => $key,
                    'duplicated_from_id' => $source->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CampaignsException::keyTaken('campaign', $key);
            }

            $this->addRevision->execute(
                $campaign,
                $revision->contentsToCopy(),
                "Duplicated from campaign \"{$source->key}\", revision {$revision->number}",
                $author,
            );

            return $campaign->refresh();
        });
    }
}
