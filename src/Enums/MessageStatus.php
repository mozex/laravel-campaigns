<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * A message moves pending, queued, sending, sent, delivered, or ends failed,
 * skipped or unknown. The one step back is queued to pending, for a claim
 * whose job never ran; nothing ever moves back from sending, since the
 * provider may already have the email.
 */
enum MessageStatus: string
{
    case Pending = 'pending';

    /** Claimed by one pump run and handed to the queue. */
    case Queued = 'queued';

    /** Taken by its send job, which is about to call the provider. */
    case Sending = 'sending';

    /** Accepted by the provider. */
    case Sent = 'sent';

    case Delivered = 'delivered';

    case Failed = 'failed';

    /** Not sent: the address unsubscribed, opted out or was blocked after the campaign started. */
    case Skipped = 'skipped';

    /** The provider call ended without a clear answer; it is looked up, never resent blindly. */
    case Unknown = 'unknown';
}
