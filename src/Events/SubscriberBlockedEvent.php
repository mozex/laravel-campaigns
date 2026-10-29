<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * An address was blocked: a hard bounce, a spam report, repeated soft
 * bounces, the provider's own suppression, an import or an agent. The
 * context says where it came from. Fired once the change commits; a
 * listener that fails is reported, never thrown (Support\AfterCommit).
 */
class SubscriberBlockedEvent
{
    use Dispatchable;

    public function __construct(
        public Subscriber $subscriber,
        public BlockReason $reason,
        public ConsentContext $context,
    ) {}
}
