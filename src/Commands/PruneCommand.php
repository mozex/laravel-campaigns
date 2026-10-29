<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\PruneEventsAction;
use Mozex\Campaigns\Actions\PruneUploadsAction;

class PruneCommand extends Command
{
    protected $signature = 'campaigns:prune';

    protected $description = 'Delete old provider events (freezing finished campaigns\' reports first), spent webhook tokens and expired uploads';

    public function handle(PruneEventsAction $events, PruneUploadsAction $uploads): int
    {
        $pruned = $events->execute();
        $deletedUploads = $uploads->execute();

        $this->info(sprintf(
            'Froze %d campaign reports, deleted %d provider events, %d webhook tokens and %d expired uploads.',
            $pruned['frozen'],
            $pruned['events'],
            $pruned['tokens'],
            $deletedUploads,
        ));

        return self::SUCCESS;
    }
}
