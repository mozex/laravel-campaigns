<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * Why an address can't be mailed. A block is a fact about the address, never
 * a choice the person made, so it stays with the address.
 */
enum BlockReason: string
{
    case HardBounce = 'hard_bounce';

    /** The person reported an email as spam. */
    case Complaint = 'complaint';

    /** Several temporary failures in a row with no delivery between them. */
    case SoftBounces = 'soft_bounces';

    case Manual = 'manual';

    /** Blocked in another system before it reached this one. */
    case Imported = 'imported';
}
