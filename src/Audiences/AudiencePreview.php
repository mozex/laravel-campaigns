<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Audiences;

/**
 * Who a campaign would reach, in counts only: no address ever appears.
 * The breakdowns count the recipients; a person on two target lists counts
 * once in each list's number. The dropped reasons don't overlap: someone
 * blocked counts as blocked whatever else holds, then opted out, then
 * sunset, so recipients and the dropped add up to the targeted.
 */
readonly class AudiencePreview
{
    /**
     * @param  array{blocked: int, opted_out: int, sunset: int}  $dropped
     * @param  array<string, int>  $byList  by list key
     * @param  array<string, int>  $byLocale  by the subscriber's locale, "none" for none
     * @param  array<string, int>  $byTier
     * @param  array<string, int>  $byProvider
     */
    public function __construct(
        public int $recipients,
        public int $targeted,
        public array $dropped,
        public array $byList,
        public array $byLocale,
        public array $byTier,
        public array $byProvider,
        public int $unmatchedEmails = 0,
    ) {}

    /**
     * @return array{recipients: int, targeted: int, dropped: array{blocked: int, opted_out: int, sunset: int}, by_list: array<string, int>, by_locale: array<string, int>, by_tier: array<string, int>, by_provider: array<string, int>, unmatched_emails: int}
     */
    public function toArray(): array
    {
        return [
            'recipients' => $this->recipients,
            'targeted' => $this->targeted,
            'dropped' => $this->dropped,
            'by_list' => $this->byList,
            'by_locale' => $this->byLocale,
            'by_tier' => $this->byTier,
            'by_provider' => $this->byProvider,
            'unmatched_emails' => $this->unmatchedEmails,
        ];
    }
}
