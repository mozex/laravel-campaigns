<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Mozex\Campaigns\Enums\FeedbackType;

/**
 * One event the provider reported about one email, in the package's terms.
 * messageUuid is the id the package sent with the message, when the provider
 * echoed it back; bot names the machine that opened or clicked, if any.
 * replayKey is the one-time value a signed webhook carries, stored with the
 * event so the same request can't be processed twice; events read from the
 * provider's logs have none.
 */
readonly class Feedback
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $providerEventId,
        public FeedbackType $type,
        public CarbonInterface $occurredAt,
        public string $recipient,
        public ?string $messageUuid = null,
        public ?string $providerMessageId = null,
        public ?string $severity = null,
        public ?string $reason = null,
        public ?int $code = null,
        public ?string $bot = null,
        public ?string $url = null,
        public ?string $replayKey = null,
        public array $payload = [],
    ) {}
}
