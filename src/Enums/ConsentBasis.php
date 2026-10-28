<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * Why the app may email someone, recorded with every consent change so the
 * records can answer a complaint.
 */
enum ConsentBasis: string
{
    /** An existing customer, offered a way to opt out when their details were collected. */
    case SoftOptIn = 'soft_opt_in';

    /** The person agreed, for example by ticking a box or confirming a double opt-in. */
    case Consent = 'consent';

    /** Brought over from another system, with its original list and date in the record's meta. */
    case Imported = 'imported';

    /** An account that existed before the app started recording consent. */
    case Legacy = 'legacy';
}
