<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;

/**
 * The package's code for one sending service: it hands each email to the
 * service, one at a time. Sending is all a driver must do; what else its
 * service offers it says by implementing the abilities in Contracts\Drivers
 * (webhooks, logs, suppression lists, daily counts), and every caller asks
 * for an ability before using it, saying why when a driver lacks it.
 *
 * A failure is decided by transport facts (no connection, no answer), the
 * service's status and its error code, never by its words, which change
 * without notice. No message a driver throws holds a recipient's address or
 * chains the transport's exception, whose message may quote one: an error
 * tracker sends both.
 */
interface CampaignDriver
{
    /**
     * Hands one message to the service. The receipt carries the service's
     * own id for it. Anything short of an accepted message throws, saying
     * whether the service took nothing (it may go later), refuses the whole
     * sending domain, or may have taken it (it must never be sent again
     * blindly).
     *
     * @throws SendFailed
     */
    public function send(OutgoingMessage $message): SendReceipt;
}
