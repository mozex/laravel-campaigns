<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Mozex\Campaigns\Contracts\CampaignProvider;

/**
 * A provider that sends nothing, for tests and local runs: it keeps every
 * message it is given and answers with a made-up id, or with the failures it
 * was told to give (failNext()). Select it with campaigns.provider "fake";
 * it is a singleton, so a test reads what was sent from the container. It
 * keeps every message for the life of the process, so it suits tests and
 * short local runs, not a long-running worker.
 */
class FakeProvider implements CampaignProvider
{
    /** @var list<OutgoingMessage> */
    protected array $sent = [];

    /** @var list<SendFailed> */
    protected array $failures = [];

    public function send(OutgoingMessage $message): SendReceipt
    {
        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }

        $this->sent[] = $message;

        return new SendReceipt('fake.'.$message->uuid.'@campaigns.test');
    }

    /**
     * The next sends throw these, in order; later sends succeed again.
     */
    public function failNext(SendFailed ...$failures): static
    {
        $this->failures = [...$this->failures, ...array_values($failures)];

        return $this;
    }

    /**
     * @return list<OutgoingMessage>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        return [];
    }

    public function eventsForMessage(string $messageUuid): array
    {
        return [];
    }

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        return [];
    }

    public function suppressions(string $domain): iterable
    {
        return [];
    }

    public function tracks(): bool
    {
        return true;
    }
}
