<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

/**
 * What one pump run did, by campaign id.
 */
readonly class PumpResult
{
    /**
     * @param  bool  $enabled  false when sending is switched off, and the run did nothing
     * @param  list<int>  $started  scheduled campaigns whose time came
     * @param  list<int>  $handedBack  starts whose freeze was lost, back to drafts
     * @param  array<int, int>  $claimed  messages handed to the queue, per campaign
     * @param  list<int>  $stalled  campaigns whose queued messages aren't moving
     * @param  list<int>  $completed  campaigns with nothing left to send
     */
    public function __construct(
        public bool $enabled,
        public array $started = [],
        public array $handedBack = [],
        public array $claimed = [],
        public array $stalled = [],
        public array $completed = [],
    ) {}
}
