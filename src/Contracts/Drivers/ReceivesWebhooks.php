<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

use Illuminate\Http\Request;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\Feedback;

/**
 * A driver whose service posts what happened to its emails to
 * /campaigns/webhooks/{driver}. A request older than 24 hours, or dated more
 * than 24 hours ahead, is refused, since the package keeps the one-time
 * values it has seen for 48 hours (WebhookToken::KEEP_HOURS).
 */
interface ReceivesWebhooks
{
    /**
     * Whether a webhook request is the service's own (its signature, or the
     * shared secret it carries).
     *
     * @throws CampaignsException when the driver isn't set up to verify one,
     *                            so the request answers 500 and the service
     *                            retries while the config is fixed
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * The events a verified webhook request reports.
     *
     * @return list<Feedback>
     */
    public function parseWebhook(Request $request): array;
}
