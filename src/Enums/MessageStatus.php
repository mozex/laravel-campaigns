<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * A message moves pending, queued, sending, sent, delivered, or ends failed,
 * skipped or unknown. Two steps lead back to pending: queued, for a claim
 * whose job never sent it, and sending, only when the provider answered that
 * it took nothing (not accepted, or the sending domain refused). Nothing else
 * moves back from sending, since the provider may already have the email.
 *
 * The provider's events only move a message forward: any event about it
 * proves the provider took it (a message not yet marked sent becomes sent,
 * and is never sent again), delivered follows sent, and a final failure
 * follows sent or delivered (a late bounce). Nothing leaves failed.
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
