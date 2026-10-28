<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What importing one contact changed.
 */
enum ImportChange: string
{
    /** A subscriber was made for the address. */
    case Created = 'created';

    /** An existing subscriber gained details it lacked, or a later engagement date. */
    case Updated = 'updated';

    /** The subscriber joined the import's list. */
    case Joined = 'joined';

    /** The subscriber was on the import's list already, and its place there was left as it is. */
    case AlreadyOnList = 'already_on_list';

    case Blocked = 'blocked';

    case OptedOut = 'opted_out';

    /**
     * The address is kept only so it is never mailed (a person who left, or
     * a block or opt-out brought in with no list), so the import added
     * nothing to it but its own block or opt-out.
     */
    case KeptSuppressed = 'kept_suppressed';

    /** No subscriber has the address, and the import has no list to add it to. */
    case NotFound = 'not_found';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New subscribers',
            self::Updated => 'Details or engagement updated',
            self::Joined => 'Added to the list',
            self::AlreadyOnList => 'Already on the list',
            self::Blocked => 'Blocked',
            self::OptedOut => 'Opted out',
            self::KeptSuppressed => 'Kept only as suppressed, nothing added',
            self::NotFound => 'Not found, and no list to add it to',
        };
    }
}
