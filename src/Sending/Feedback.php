<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackType;

/**
 * One event a driver reported about one email, in the package's terms.
 * providerEventId is the service's own id for the event, the same whether it
 * came by webhook or from the service's logs. messageUuid is the id the
 * package sent with a campaign's message and testSendUuid the one it sent
 * with a test email, when the service echoed them back; bot names the
 * machine that opened or clicked, if any. replayKey is the one-time value a
 * signed webhook carries, recorded with the event's id so a replayed request
 * can't carry another event; events read from the logs, and webhooks whose
 * every request is verified by a shared secret, have none. payload is the
 * service's own details worth keeping, never a recipient's address or IP.
 * failure is what a failed event means in the package's terms, which the
 * driver works out from the service's own fields.
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
        public ?string $messageUuid = null,
        public ?string $testSendUuid = null,
        public ?string $providerMessageId = null,
        public ?string $severity = null,
        public ?string $reason = null,
        public ?DeliveryFailure $failure = null,
        public ?int $code = null,
        public ?string $bot = null,
        public ?string $url = null,
        public ?string $replayKey = null,
        public array $payload = [],
    ) {}
}
