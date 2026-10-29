<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Mozex\Campaigns\Enums\DeliveryFailure;

/**
 * What applying one provider event changed about a campaign's message, the
 * changes a campaign's limits are checked against: it failed now (and how),
 * its first spam report, or the unsubscribe that opted the person out.
 * campaignId is null for an event about no campaign message (a test email's,
 * or one whose message is gone).
 */
readonly class AppliedFeedback
{
    public function __construct(
        public ?int $campaignId = null,
        public ?DeliveryFailure $failed = null,
        public bool $complained = false,
        public bool $unsubscribed = false,
    ) {}
}
