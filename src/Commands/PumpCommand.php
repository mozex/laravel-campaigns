<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Commands;

use Illuminate\Console\Command;
use Mozex\Campaigns\Actions\PumpCampaignsAction;

class PumpCommand extends Command
{
    protected $signature = 'campaigns:pump';

    protected $description = 'Start scheduled campaigns and hand each sending campaign\'s next messages to the queue, at its pace';

    public function handle(PumpCampaignsAction $pump): int
    {
        $result = $pump->execute();

        if (! $result->enabled) {
            $this->info('Sending is switched off (campaigns.enabled), so nothing was done.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Started %d scheduled, handed back %d lost starts, queued %d messages for %d campaigns, %d stalled, %d completed.',
            count($result->started),
            count($result->handedBack),
            array_sum($result->claimed),
            count($result->claimed),
            count($result->stalled),
            count($result->completed),
        ));

        return self::SUCCESS;
    }
}
