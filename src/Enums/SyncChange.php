<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What syncing one app record changed on its dynamic list.
 */
enum SyncChange: string
{
    /** A subscriber was made for the record's address. */
    case Created = 'created';

    /** The record was linked to a subscriber its address already had. */
    case Linked = 'linked';

    /** The subscriber joined the list. */
    case Added = 'added';

    /** The subscriber joined the list with its sign-up box unticked, opted out. */
    case Declined = 'declined';

    /** The subscriber's details changed to match the record. */
    case Updated = 'updated';

    /** The record's address changed, and the person moved with it. */
    case EmailChanged = 'email_changed';

    /** The record stopped being eligible, or lost an address that can be synced, and left the list. */
    case Removed = 'removed';

    /** The record can be on the list again (eligible, with an address that syncs) and is back on it. */
    case Restored = 'restored';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'New subscribers',
            self::Linked => 'Linked to an existing address',
            self::Added => 'Added to the list',
            self::Declined => 'Added, opted out at sign-up',
            self::Updated => 'Details updated',
            self::EmailChanged => 'Address changed',
            self::Removed => 'Taken off (not eligible, or no address to sync)',
            self::Restored => 'Put back on the list',
        };
    }
}
