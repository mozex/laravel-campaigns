<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * An address was blocked: a hard bounce, a spam report, repeated soft
 * bounces, the provider's own suppression, an import or an agent. The
 * context says where it came from. Fired once the change commits; a
 * listener that fails is reported, never thrown (Support\AfterCommit). A
 * queued listener gets the subscriber by id, never its address and details
 * in the payload; the context still carries the person's IP address and
 * browser, so such a listener leaves them out of what it stores.
 */
class SubscriberBlockedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Subscriber $subscriber,
        public BlockReason $reason,
        public ConsentContext $context,
    ) {}
}
