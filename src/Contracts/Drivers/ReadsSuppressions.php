<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts\Drivers;

use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Sending\Suppression;

/**
 * A driver whose service keeps lists of addresses it refuses to send to,
 * which campaigns:import-provider-suppressions and an unblock's check read.
 * The domain is the driver's sending scope: a service with one list for the
 * whole account ignores it, and its docs say so. A read that can't be made
 * throws a CampaignsException whose message holds no address.
 */
interface ReadsSuppressions
{
    /**
     * The addresses the service refuses to send to from a domain.
     *
     * @return iterable<Suppression>
     *
     * @throws CampaignsException
     */
    public function suppressions(string $domain): iterable;

    /**
     * Whether the service holds one address on a domain's suppression lists,
     * and on which: an empty list when it doesn't.
     *
     * @return list<Suppression>
     *
     * @throws CampaignsException
     */
    public function suppressionsFor(string $domain, string $email): array;
}
