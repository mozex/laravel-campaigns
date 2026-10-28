<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum SubscriptionStatus: string
{
    /** Waiting for the person to confirm a double opt-in. */
    case Pending = 'pending';

    case Subscribed = 'subscribed';

    /**
     * Off a dynamic list while the app record behind it can't be on it (not
     * eligible, or without an address that can be synced), and back on once
     * it can. A person's own unsubscribe is an opt-out on the subscriber, and
     * an agent taking someone off a static list deletes the row.
     */
    case Unsubscribed = 'unsubscribed';
}
