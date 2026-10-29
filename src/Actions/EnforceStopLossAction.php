<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Events\CampaignFirstComplaintEvent;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Sending\AppliedFeedback;
use Mozex\Campaigns\Sending\StopLoss;
use Mozex\Campaigns\Support\AfterCommit;
use Throwable;

/**
 * The stop-loss, run after a campaign's email failed, drew a spam report or
 * was unsubscribed from: a sending campaign past one of its limits pauses
 * itself, and a sending domain past its own over the last 24 hours pauses
 * every campaign on it (Sending\StopLoss holds the limits), each through
 * PauseCampaignAction as an automatic pause with the reason. A campaign's
 * first spam report, while it keeps sending within its limits and its
 * domain's, is only told (CampaignFirstComplaintEvent).
 *
 * It runs after the change has committed and never throws: its caller (the
 * feedback job, which won't apply its event a second time) must not fail,
 * and the next failure, report or unsubscribe measures again from the
 * messages, so a check lost to an error is made up by the next one. A
 * listener that fails is reported, and the domain's other campaigns still
 * pause.
 */
class EnforceStopLossAction
{
    public function __construct(protected PauseCampaignAction $pause) {}

    public function execute(AppliedFeedback $applied): void
    {
        if ($applied->campaignId === null || ($applied->failed === null && ! $applied->complained && ! $applied->unsubscribed)) {
            return;
        }

        $campaign = rescue(fn (): ?Campaign => Campaign::query()->find($applied->campaignId));

        if (! $campaign instanceof Campaign) {
            return;
        }

        $paused = rescue(fn (): bool => $this->campaign($campaign), false);

        // The domain has no unsubscribe limit.
        if ($applied->failed !== null || $applied->complained) {
            rescue(fn () => $this->domain($campaign->sendingDomain(), $paused ? [$campaign->id] : []));
        }

        // Last, so a campaign either check just paused isn't told it keeps
        // sending.
        if ($applied->complained && ! $paused) {
            rescue(fn () => $this->firstComplaint($campaign->id));
        }
    }

    /**
     * Pauses the campaign when it is sending and past one of its limits.
     * Returns whether they tripped.
     */
    protected function campaign(Campaign $campaign): bool
    {
        if ($campaign->status !== CampaignStatus::Sending) {
            return false;
        }

        $reason = StopLoss::campaignBreach($campaign);

        if ($reason === null) {
            return false;
        }

        try {
            // The pause and the new start of its limits commit together;
            // the pause's event follows the commit.
            $campaign->getConnection()->transaction(function () use ($campaign, $reason): void {
                $this->pause->execute($campaign, 'The stop-loss paused it: '.$reason, automatic: true);

                Campaign::query()->whereKey($campaign->id)->update(['limits_reset_at' => now(), 'updated_at' => now()]);
            });
        } catch (CampaignsException) {
            // It completed or was cancelled meanwhile: nothing to pause.
        } catch (Throwable $exception) {
            // A listener that failed after the commit: it is paused.
            report($exception);
        }

        return true;
    }

    /**
     * Tells of a campaign's first spam report (since its limits last started
     * again), only while it still sends: a finished or paused campaign never
     * pauses on its own limits, which the warning would promise.
     */
    protected function firstComplaint(int $campaignId): void
    {
        $campaign = Campaign::query()->find($campaignId);

        if ($campaign?->status === CampaignStatus::Sending && StopLoss::complaints($campaign) === 1) {
            AfterCommit::dispatch($campaign->getConnection(), new CampaignFirstComplaintEvent($campaign));
        }
    }

    /**
     * Pauses every sending or scheduled campaign on a domain past its limits,
     * and starts the domain's numbers again. A domain with nothing running
     * (its campaigns finished, its late bounces still arriving) is left
     * alone, so a campaign started on it later is paused at its first
     * failure while the 24 hours still hold them.
     *
     * @param  list<int>  $justPaused  campaigns of the domain this check already paused
     */
    protected function domain(string $domain, array $justPaused): void
    {
        $now = now();

        $running = Campaign::query()
            ->whereIn('status', [CampaignStatus::Sending, CampaignStatus::Scheduled])
            ->orderBy('id')
            ->get(['id', 'from_email'])
            ->filter(fn (Campaign $campaign): bool => $campaign->sendingDomain() === $domain)
            ->modelKeys();

        // Looked at first: the measure is the dearer read, and a finished
        // campaign's late bounces would otherwise run it each time.
        if ($running === [] && $justPaused === []) {
            return;
        }

        $reason = StopLoss::domainBreach($domain, $now);

        if ($reason === null) {
            return;
        }

        $reason = "The stop-loss paused every campaign on {$domain}: over the domain's last ".StopLoss::DOMAIN_HOURS.' hours, '.$reason;

        foreach ($running as $id) {
            // Each found on its own, so the event's listeners may read its
            // relations under strict models.
            $campaign = Campaign::query()->find($id);

            if ($campaign === null) {
                continue;
            }

            try {
                $this->pause->execute($campaign, $reason, automatic: true);
            } catch (CampaignsException) {
                // It completed or was cancelled meanwhile.
            } catch (Throwable $exception) {
                // A listener that failed: the others still pause.
                report($exception);
            }
        }

        // Written after the pauses, so a run that dies between them leaves
        // the domain's numbers as they were, and the next failure pauses and
        // writes it again.
        $measured = StopLoss::domainCampaigns($domain, $now->copy()->subHours(StopLoss::DOMAIN_HOURS))->modelKeys();

        // Forward only: an earlier run that commits later must not move the
        // floor back and count the bounces that paused the domain again.
        Campaign::query()
            ->whereIn('id', array_values(array_unique([...$measured, ...$running, ...$justPaused])))
            ->where(fn (Builder $query): Builder => $query->whereNull('domain_limits_reset_at')->orWhere('domain_limits_reset_at', '<', $now))
            ->update(['domain_limits_reset_at' => $now, 'updated_at' => $now]);
    }
}
