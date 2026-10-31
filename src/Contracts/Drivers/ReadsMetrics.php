<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

use Carbon\CarbonInterface;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\DomainMetrics;

/**
 * A driver whose service counts a sending domain's mail by day, which the
 * overview shows an agent judging the domain's reputation and quota before a
 * start.
 */
interface ReadsMetrics
{
    /**
     * What the service counted for a sending domain each day of a period.
     * The days are whole UTC days, from the one holding $from to the one
     * holding $to, and none outside them; a count the service doesn't keep
     * is null.
     *
     * @throws CampaignsException
     */
    public function domainMetrics(string $domain, CarbonInterface $from, CarbonInterface $to): DomainMetrics;
}
