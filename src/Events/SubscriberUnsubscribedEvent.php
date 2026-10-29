<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * A person stopped all of the app's marketing email: an unsubscribe (an
 * email's link, a mail app's one-click, the provider's own), a spam report,
 * an unticked sign-up box, an import or an agent. The context says which.
 * Fired once the change commits; a listener that fails is reported, never
 * thrown (Support\AfterCommit). A queued listener gets the subscriber by
 * id, never its address and details in the payload; the context still
 * carries the person's IP address and browser, so such a listener leaves
 * them out of what it stores.
 */
class SubscriberUnsubscribedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Subscriber $subscriber,
        public ConsentContext $context,
    ) {}
}
