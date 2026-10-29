<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mozex\Campaigns\Audiences\CampaignAudience;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\ContentRules;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Sending\Pace;
use Mozex\Campaigns\Support\Sender;

/**
 * Starts a draft campaign now, or schedules it for a time, and returns at
 * once: it claims the campaign (status starting) and queues the freeze,
 * which reads the audience, holds the recipient count to the one given and
 * moves the campaign to sending or scheduled, or back to a draft with
 * start_error saying why. The count is the audience preview's: a start never
 * reaches people nobody counted.
 */
class StartCampaignAction
{
    public function __construct(protected ContentRules $rules) {}

    /**
     * @param  int  $expectedRecipients  the recipients the audience preview showed
     * @param  ?CarbonInterface  $at  when to start; now when null or past
     * @param  ?array<string, mixed>  $pace  pace settings to lay over the campaign's; they are its settings from then on, kept even when the freeze refuses the start
     *
     * @throws CampaignsException|ValidationException
     */
    public function execute(Campaign $campaign, int $expectedRecipients, ?CarbonInterface $at = null, ?array $pace = null): Campaign
    {
        if (! config('campaigns.enabled')) {
            throw CampaignsException::sendingDisabled();
        }

        if ($expectedRecipients < 1) {
            throw CampaignsException::invalidRecipientCount();
        }

        if ($at !== null && $at->gt(now()->addYear())) {
            throw CampaignsException::scheduleTooFar();
        }

        $pace = $pace === null ? [] : Pace::validate($pace);

        if (! Sender::isOnDomain($campaign->from_email)) {
            throw CampaignsException::notOnSendingDomain(Sender::domain());
        }

        // Everything the freeze needs is checked now, so a start that can't
        // work is refused in the call: the audience's parts, and the email
        // still meeting the rules once its links carry the UTM tags.
        CampaignAudience::for($campaign)->targeted();
        $revision = $campaign->currentRevision()->with('contents')->first() ?? throw CampaignsException::noRevision();
        $tagged = FreezeCampaignAction::taggedContents($campaign, $revision);

        if ($tagged !== null) {
            $this->rules->prepare($tagged);
        }

        $run = (string) Str::uuid();

        // Stored in the app's timezone, which Eloquent writes a date in as
        // its own: a time given in another would land hours off.
        $at = $at !== null && $at->isFuture() ? Carbon::instance($at)->setTimezone((string) config('app.timezone')) : null;

        $campaign->getConnection()->transaction(function () use ($campaign, $at, $pace, $run): void {
            // The lock AddRevisionAction and UpdateCampaignAction take, so an
            // edit lands before the claim or is refused after it.
            $locked = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->first()
                ?? throw CampaignsException::invalidValue('campaign', 'was deleted');

            if ($locked->status !== CampaignStatus::Draft) {
                throw CampaignsException::campaignCannot('started', $locked->status->value);
            }

            $locked->forceFill([
                'status' => CampaignStatus::Starting,
                'start_error' => null,
                'start_run' => $run,
                'scheduled_at' => $at,
                'pace' => [...$locked->pace ?? [], ...$pace],
            ])->save();
        });

        FreezeCampaignJob::dispatch($campaign->id, $expectedRecipients, $run)->afterCommit();

        // On a sync queue the freeze has already run.
        return $campaign->refresh();
    }
}
