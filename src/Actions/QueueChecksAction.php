<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Actions;

use Illuminate\Support\Str;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Content\Locales;
use Mozex\Campaigns\Jobs\RunChecksJob;
use Mozex\Campaigns\Models\Revision;

/**
 * Queues the pre-send checks for a revision and returns at once: fetching
 * every link takes longer than an agent's tool call may wait. The
 * revision's check_results read "queued" until the job stores its results
 * there; queuing again replaces a run still waiting, whose results are then
 * dropped.
 */
class QueueChecksAction
{
    /**
     * @param  ?list<string>  $locales  the languages the recipients speak, when known
     * @param  bool  $clickTracking  whether the links will be wrapped for click tracking (a campaign's setting; on for a template)
     *
     * @throws CampaignsException for a locale that isn't one
     */
    public function execute(Revision $revision, ?array $locales = null, bool $clickTracking = true): string
    {
        foreach ($locales ?? [] as $locale) {
            if (! Locales::isValid($locale)) {
                throw CampaignsException::invalidLocale($locale);
            }
        }

        $run = (string) Str::uuid();

        $revision->forceFill([
            'check_results' => [
                'status' => 'queued',
                'run' => $run,
                'queued_at' => now()->toIso8601String(),
            ],
        ])->save();

        RunChecksJob::dispatch($revision->id, $run, $locales, $clickTracking)->afterCommit();

        return $run;
    }
}
