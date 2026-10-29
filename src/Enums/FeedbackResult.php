<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What recording one provider event did.
 */
enum FeedbackResult: string
{
    /** Stored, and queued for its effects. */
    case Stored = 'stored';

    /** The same event was stored before (a retry, or read again from the provider's logs). */
    case Duplicate = 'duplicate';

    /** A webhook's one-time token came back with another event: a replayed request. */
    case Refused = 'refused';

    /** About mail the package didn't send (another sender on the domain, a forward): nothing to do. */
    case Unknown = 'unknown';
}
