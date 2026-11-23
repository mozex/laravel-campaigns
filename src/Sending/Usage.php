<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;

/**
 * What the service counts against the account's sending allowance: over
 * the calendar month (UTC) for a plan with a monthly volume, or over the
 * last 24 hours for a rolling quota. The count is the whole account's,
 * every app and domain on it, since the allowance is the account's.
 */
readonly class Usage
{
    /** The calendar month (UTC), so far. */
    public const MONTH = 'month';

    /** The last 24 hours. */
    public const DAY = 'last_24_hours';

    /**
     * @param  string  $period  MONTH or DAY
     * @param  int  $sent  what the service counted in the period
     * @param  ?int  $allowance  what the account may send in the period; null when it has no limit or none is known
     * @param  bool  $enforced  whether the service holds mail past the allowance back (a quota) rather than billing it (a plan's volume)
     * @param  ?float  $perSecond  the most emails the service takes a second; null when it sets none
     * @param  list<string>  $notes  what the count leaves out, or what the account's state means for sending
     */
    public function __construct(
        public string $period,
        public CarbonInterface $from,
        public CarbonInterface $to,
        public int $sent,
        public ?int $allowance,
        public bool $enforced,
        public ?float $perSecond = null,
        public array $notes = [],
    ) {}

    /**
     * What the allowance leaves, 0 once passed; null without one (an
     * allowance below 0 counts as none).
     */
    public function left(): ?int
    {
        return $this->allowance === null || $this->allowance < 0 ? null : max(0, $this->allowance - max(0, $this->sent));
    }
}
