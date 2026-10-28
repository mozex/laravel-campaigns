<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Support;

use Illuminate\Http\Request;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentBasis;

/**
 * Who changed someone's consent, how and why, written into the consent
 * records with every change so they can answer a complaint.
 */
readonly class ConsentContext
{
    /**
     * @param  string  $source  Where the change came from: registration, checkout, staff, an import's name, a form's key.
     * @param  ?ConsentBasis  $basis  Why the app may email the person; null takes the list's default.
     * @param  ?string  $noticeVersion  The version of the wording the person was shown.
     * @param  ?bool  $accepted  What the person chose, when they chose: true when they asked for email (ticked the sign-up box, turned the switch on, subscribed again), false when they left the sign-up box unticked, null when the app or an agent acted for them. Only true clears an earlier opt-out.
     * @param  ?string  $ipAddress  The person's IP address, when they acted themselves.
     * @param  ?string  $userAgent  The person's browser, when they acted themselves.
     * @param  array<string, mixed>  $meta  Anything else worth keeping with the record, such as an import's original list and date.
     */
    public function __construct(
        public string $source,
        public ?ConsentBasis $basis = null,
        public ?string $noticeVersion = null,
        public ?bool $accepted = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public array $meta = [],
    ) {
        if (mb_strlen($source) > 125) {
            throw CampaignsException::valueTooLong('source', 125);
        }

        if ($noticeVersion !== null && mb_strlen($noticeVersion) > 64) {
            throw CampaignsException::valueTooLong('notice version', 64);
        }
    }

    /**
     * A change the person made through a request, carrying their IP address
     * and browser.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function fromRequest(
        Request $request,
        string $source,
        ?ConsentBasis $basis = null,
        ?string $noticeVersion = null,
        ?bool $accepted = null,
        array $meta = [],
    ): self {
        return new self(
            source: $source,
            basis: $basis,
            noticeVersion: $noticeVersion,
            accepted: $accepted,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            meta: $meta,
        );
    }
}
