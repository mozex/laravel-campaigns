<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\ReconcileCampaignsAction;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Sending\Drivers\DriverManager;

class ReconcileCommand extends Command
{
    protected $signature = 'campaigns:reconcile';

    protected $description = 'Put back messages whose job was lost, and settle what webhooks missed from the provider\'s logs';

    public function handle(ReconcileCampaignsAction $reconcile): int
    {
        $result = $reconcile->execute();

        $this->info(sprintf(
            'Put back %d queued messages whose job was lost, queued %d unprocessed events again, stored %d events from the provider\'s logs, found %d unclear messages there and failed %d it never took, and left %d unknown whose driver reads no logs.',
            $result->released,
            $result->requeued,
            $result->replayed,
            $result->found,
            $result->lost,
            $result->settled,
        ));

        $drivers = app(DriverManager::class);
        $missing = rescue(fn (): ?string => DriverManager::missingSetup($drivers->active(), ReadsEvents::class), report: false);

        if ($missing !== null) {
            $this->line("The {$drivers->getDefaultDriver()} driver's logs are read once {$missing}.");
        }

        foreach ($result->problems as $problem) {
            $this->error($problem);
        }

        return $result->problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
