<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Sending\Suppression;

/**
 * The service that delivers campaign email and reports back on it. The
 * package sends one message per call and learns what happened through
 * webhooks, with the provider's logs as the fallback for anything a webhook
 * missed. A read that can't be made throws a CampaignsException whose message
 * holds no address.
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
     * The events the provider logged for one message since a time, found by
     * the uuid the package sent with it. Used when a message's outcome is
     * unclear: an empty list means the provider's logs hold nothing for it,
     * and null that they held more than a lookup reads, so nothing can be
     * told from them.
     *
     * @return ?list<Feedback>
     *
     * @throws CampaignsException
     */
    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array;

    /**
     * The events the provider logged for a sending domain in a period, oldest
     * first, read a page at a time, used to replay what webhooks missed.
     *
     * @return iterable<Feedback>
     *
     * @throws CampaignsException
     */
    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable;

    /**
     * How many days back the provider's logs reach, so nothing older is
     * looked for, and a message the logs can no longer show is never judged
     * lost.
     */
    public function logDays(): int;

    /**
     * The addresses the provider refuses to send to from a domain.
     *
     * @return iterable<Suppression>
     *
     * @throws CampaignsException
     */
    public function suppressions(string $domain): iterable;

    /**
     * Whether the provider holds one address on a domain's suppression lists,
     * and on which: an empty list when it doesn't.
     *
     * @return list<Suppression>
     *
     * @throws CampaignsException
     */
    public function suppressionsFor(string $domain, string $email): array;

    /**
     * Whether the provider tracks opens and clicks itself, so the package
     * adds no pixel or link rewriting of its own.
     */
    public function tracks(): bool;
}
