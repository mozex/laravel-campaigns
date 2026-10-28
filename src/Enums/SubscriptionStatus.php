<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum SubscriptionStatus: string
{
    /** Waiting for the person to confirm a double opt-in. */
    case Pending = 'pending';

    case Subscribed = 'subscribed';

    /**
     * Off a dynamic list while the app record behind it isn't eligible, and
     * back on once it is. A person's own unsubscribe is an opt-out on the
     * subscriber, and an agent taking someone off a list deletes the row.
     */
    case Unsubscribed = 'unsubscribed';
}
