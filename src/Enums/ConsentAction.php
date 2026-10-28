<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum ConsentAction: string
{
    case Subscribe = 'subscribe';

    case Confirm = 'confirm';

    case Unsubscribe = 'unsubscribe';

    /** Stopped all marketing email from the app. */
    case OptOut = 'opt_out';

    /** Cleared an earlier opt-out. */
    case OptIn = 'opt_in';

    case Block = 'block';

    case Unblock = 'unblock';
}
