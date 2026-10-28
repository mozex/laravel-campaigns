<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

enum FeedbackType: string
{
    case Accepted = 'accepted';

    case Delivered = 'delivered';

    /** A temporary or permanent failure; the severity says which. */
    case Failed = 'failed';

    case Opened = 'opened';

    case Clicked = 'clicked';

    case Unsubscribed = 'unsubscribed';

    /** The recipient reported the email as spam. */
    case Complained = 'complained';
}
