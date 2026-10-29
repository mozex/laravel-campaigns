<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Sending\Suppression;

/**
 * The service that delivers campaign email and reports back on it. The
 * package sends one message per call and learns what happened through
 * webhooks, with the provider's logs as the fallback for anything a webhook
 * missed.
 */
interface CampaignProvider
{
    /**
     * Hands one message to the provider. The receipt carries the provider's
     * own id for it. Anything short of an accepted message throws, saying
     * whether the provider took nothing (it may go later), refuses the whole
     * sending domain, or may have taken it (it must never be sent again
     * blindly).
     *
     * @throws SendFailed
     */
    public function send(OutgoingMessage $message): SendReceipt;

    /**
     * Whether a webhook request carries a valid signature from the provider.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * The events a verified webhook request reports.
     *
     * @return list<Feedback>
     */
    public function parseWebhook(Request $request): array;

    /**
     * The events the provider logged for one message, found by the uuid the
     * package sent with it. Used when a message's outcome is unclear.
     *
     * @return list<Feedback>
     */
    public function eventsForMessage(string $messageUuid): array;

    /**
     * The events the provider logged for a sending domain in a period, used
     * to replay what webhooks missed.
     *
     * @return iterable<Feedback>
     */
    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable;

    /**
     * The addresses the provider refuses to send to from a domain.
     *
     * @return iterable<Suppression>
     */
    public function suppressions(string $domain): iterable;

    /**
     * Whether the provider tracks opens and clicks itself, so the package
     * adds no pixel or link rewriting of its own.
     */
    public function tracks(): bool;
}
