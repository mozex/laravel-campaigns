<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

/**
 * What one reconciler run did.
 */
readonly class ReconcileResult
{
    /**
     * @param  int  $released  Queued messages whose job was lost, put back to pending.
     * @param  int  $requeued  Stored events still unprocessed, queued again.
     * @param  int  $replayed  Events read back from the provider's logs that no webhook had brought.
     * @param  int  $found  Unclear messages whose events the provider's logs held.
     * @param  int  $lost  Unclear messages the provider's logs hold nothing for an hour on, failed as never taken.
     * @param  list<string>  $problems  What couldn't be done, such as the provider's logs being unreadable.
     * @param  int  $settled  Messages left sending through a driver whose logs aren't read, made unknown.
     */
    public function __construct(
        public int $released = 0,
        public int $requeued = 0,
        public int $replayed = 0,
        public int $found = 0,
        public int $lost = 0,
        public array $problems = [],
        public int $settled = 0,
    ) {}
}
