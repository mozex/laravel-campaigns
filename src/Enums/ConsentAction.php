<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum ConsentAction: string
{
    /** Joined a list, subscribed or waiting to confirm. */
    case Subscribe = 'subscribe';

    /** Confirmed a double opt-in. */
    case Confirm = 'confirm';

    /**
     * Left the sign-up box unticked: on the list as a record of the app, but
     * opted out of its marketing email.
     */
    case Decline = 'decline';

    /**
     * Taken off a list by the app or an agent. A person's own unsubscribe
     * is an opt-out, never this.
     */
    case Unsubscribe = 'unsubscribe';

    /** Stopped all marketing email from the app. */
    case OptOut = 'opt_out';

    /** Cleared an earlier opt-out. */
    case OptIn = 'opt_in';

    case Block = 'block';

    case Unblock = 'unblock';
}
