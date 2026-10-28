<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

/**
 * One fully rendered email, ready for the provider. The uuid travels with the
 * message so the provider's events lead back to it; recipients can see it,
 * so it is an opaque id and nothing more.
 */
readonly class OutgoingMessage
{
    public function __construct(
        public string $uuid,
        public string $to,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $replyTo,
        public string $subject,
        public string $html,
        public string $text,
        public string $unsubscribeUrl,
        public string $tag,
        public bool $trackOpens,
        public bool $trackClicks,
    ) {}
}
