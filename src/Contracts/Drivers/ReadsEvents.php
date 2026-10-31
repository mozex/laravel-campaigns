<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

use Carbon\CarbonInterface;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\Feedback;

/**
 * A driver whose service keeps logs of its emails' events, which the
 * reconciler reads back for what a webhook missed and for messages whose
 * send ended unclear. A driver without them leaves those messages unknown,
 * never resent. A read that can't be made throws a CampaignsException whose
 * message holds no address.
 */
interface ReadsEvents
{
    /**
     * The events the service logged for one message since a time, found by
     * the uuid the package sent with it: an empty list means its logs hold
     * nothing for it, and null that they held more than a lookup reads (or
     * can't be searched that way), so nothing can be told from them. An
     * empty list fails the message as never taken an hour after it went, so
     * a driver that can't tell answers null.
     *
     * @return ?list<Feedback>
     *
     * @throws CampaignsException
     */
    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array;

    /**
     * The events the service logged for a sending domain in a period, oldest
     * first, read a page at a time, used to replay what webhooks missed.
     *
     * @return iterable<Feedback>
     *
     * @throws CampaignsException
     */
    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable;

    /**
     * How many days back the service's logs reach, so nothing older is
     * looked for, and a message the logs can no longer show is never judged
     * lost.
     */
    public function logDays(): int;
}
