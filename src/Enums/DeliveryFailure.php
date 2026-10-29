<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Enums;

/**
 * What a provider's failure report means, in the package's terms: whether
 * the provider has finished with the message, and what the failure says
 * about the address. The provider's driver maps its own words to these;
 * the effects and the numbers read only these.
 */
enum DeliveryFailure: string
{
    /** Not final: the provider is still trying, and may yet deliver it. */
    case Deferred = 'deferred';

    /**
     * Not final, and not the address's doing: the receiving mail provider
     * refuses the sender for now, or the provider hit its own limit. A
     * give-up that follows it counts nothing against the address.
     */
    case SenderDeferred = 'sender_deferred';

    /** The address doesn't exist or never will: it is blocked. */
    case HardBounce = 'hard_bounce';

    /**
     * The provider gave up after temporary failures (a full mailbox, a
     * server that never answered): one soft bounce against the address.
     */
    case SoftBounce = 'soft_bounce';

    /** Never sent: the provider holds the address as bounced. */
    case SuppressedBounce = 'suppressed_bounce';

    /** Never sent: the provider holds the address as having complained. */
    case SuppressedComplaint = 'suppressed_complaint';

    /** Never sent: the provider holds the address as unsubscribed. */
    case SuppressedUnsubscribe = 'suppressed_unsubscribe';

    /**
     * The sender was refused, not the address: the receiving mail provider
     * blocked it (its reputation, a block list), or the provider's own
     * sending limit stopped it. Nothing against the address.
     */
    case SenderBlocked = 'sender_blocked';

    /** Any other final failure: nothing against the address. */
    case Other = 'other';

    public function isFinal(): bool
    {
        return $this !== self::Deferred && $this !== self::SenderDeferred;
    }
}
