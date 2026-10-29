<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\ReconcileCampaignsAction;

class ReconcileCommand extends Command
{
    protected $signature = 'campaigns:reconcile';

    protected $description = 'Put back messages whose job was lost, and settle what webhooks missed from the provider\'s logs';

    public function handle(ReconcileCampaignsAction $reconcile): int
    {
        $result = $reconcile->execute();

        $this->info(sprintf(
            'Put back %d queued messages whose job was lost, queued %d unprocessed events again, stored %d events from the provider\'s logs, found %d unclear messages there and failed %d it never took.',
            $result->released,
            $result->requeued,
            $result->replayed,
            $result->found,
            $result->lost,
        ));

        foreach ($result->problems as $problem) {
            $this->error($problem);
        }

        return $result->problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
