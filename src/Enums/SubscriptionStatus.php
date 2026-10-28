<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum SubscriptionStatus: string
{
    /** Waiting for the person to confirm a double opt-in. */
    case Pending = 'pending';

    case Subscribed = 'subscribed';

    case Unsubscribed = 'unsubscribed';
}
