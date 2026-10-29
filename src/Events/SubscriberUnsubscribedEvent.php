<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\ConsentContext;

/**
 * A person stopped all of the app's marketing email: an unsubscribe (an
 * email's link, a mail app's one-click, the provider's own), a spam report,
 * an unticked sign-up box, an import or an agent. The context says which.
 * Fired once the change commits; a listener that fails is reported, never
 * thrown (Support\AfterCommit).
 */
class SubscriberUnsubscribedEvent
{
    use Dispatchable;

    public function __construct(
        public Subscriber $subscriber,
        public ConsentContext $context,
    ) {}
}
