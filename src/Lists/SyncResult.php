<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Lists;

use Mozex\Campaigns\Enums\SyncChange;

readonly class SyncResult
{
    /**
     * @param  list<SyncChange>  $changes  What changed; none when the list already matched the record.
     * @param  ?string  $problem  Why the record couldn't be synced, when it couldn't.
     * @param  bool  $suppressed  Whether the record's subscriber is opted out or blocked, so on the list but never mailed.
     */
    public function __construct(
        public array $changes = [],
        public ?string $problem = null,
        public bool $suppressed = false,
    ) {}
}
