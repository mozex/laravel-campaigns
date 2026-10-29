<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Mozex\Campaigns\Checks\PreSendChecks;
use Mozex\Campaigns\Enums\CheckStatus;
use Mozex\Campaigns\Models\Revision;
use Throwable;

/**
 * Runs a revision's pre-send checks and stores their results on it, unless
 * the checks were queued again meanwhile: only the latest run's results are
 * kept.
 */
class RunChecksJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    /**
     * Above PreSendChecks::RUN_SECONDS, where the checks stop on their own.
     * Give the queue's worker a longer timeout than this.
     */
    public int $timeout = 300;

    /**
     * @param  ?list<string>  $locales
     */
    public function __construct(
        public int $revisionId,
        public string $run,
        public ?array $locales = null,
        public bool $clickTracking = true,
    ) {
        $this->onConnection(config('campaigns.queue.connection'));
        $this->onQueue(config('campaigns.queue.name'));
    }

    public function handle(PreSendChecks $checks): void
    {
        $revision = Revision::query()->find($this->revisionId);

        if ($revision === null || ! $this->isCurrent($revision)) {
            return;
        }

        $results = $checks->run($revision, $this->locales, $this->clickTracking);
        $failed = count(array_filter($results, fn (array $result): bool => $result['status'] === CheckStatus::Failed->value));

        $this->store($revision, [
            'status' => 'done',
            'passed' => $failed === 0,
            'failed' => $failed,
            'checks' => $results,
        ]);
    }

    public function failed(?Throwable $exception = null): void
    {
        $revision = Revision::query()->find($this->revisionId);

        if ($revision !== null) {
            $this->store($revision, [
                'status' => 'failed',
                'message' => 'The checks stopped with an error. Queue them again.',
            ]);
        }
    }

    /**
     * Writes the results under the revision's lock, only while this run is
     * still the latest one queued.
     *
     * @param  array<string, mixed>  $results
     */
    protected function store(Revision $revision, array $results): void
    {
        $revision->getConnection()->transaction(function () use ($revision, $results): void {
            $locked = Revision::query()->whereKey($revision->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->isCurrent($locked)) {
                return;
            }

            $locked->forceFill([
                'check_results' => [
                    ...$results,
                    'run' => $this->run,
                    'queued_at' => $locked->check_results['queued_at'] ?? null,
                    'finished_at' => now()->toIso8601String(),
                ],
                'checked_at' => now(),
            ])->save();
        });
    }

    protected function isCurrent(Revision $revision): bool
    {
        return ($revision->check_results['run'] ?? null) === $this->run;
    }
}
