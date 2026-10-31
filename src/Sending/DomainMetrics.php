<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

/**
 * What the service counted for a sending domain, a day at a time (UTC
 * dates, oldest first): the emails it sent (its own outgoing mail only), the
 * inbound mail its routes forwarded on, and the hard bounces, spam reports
 * and unsubscribes it recorded. A count the service doesn't keep is null,
 * never 0, which would read as "none happened".
 */
readonly class DomainMetrics
{
    /** What a day counts. */
    public const COUNTS = ['sent', 'forwarded', 'hard_bounces', 'complaints', 'unsubscribes'];

    /**
     * @param  array<string, array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int}>  $days  by UTC date (Y-m-d)
     */
    public function __construct(public array $days) {}

    /**
     * Each count summed over the days that kept it; null when none did.
     *
     * @return array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int}
     */
    public function totals(): array
    {
        $totals = array_fill_keys(static::COUNTS, null);

        foreach ($this->days as $counts) {
            foreach (static::COUNTS as $count) {
                if ($counts[$count] !== null) {
                    $totals[$count] = ($totals[$count] ?? 0) + $counts[$count];
                }
            }
        }

        /** @var array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int} */
        return $totals;
    }
}
