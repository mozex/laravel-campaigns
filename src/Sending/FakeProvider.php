<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;

/**
 * A provider that sends nothing, for tests and local runs: it keeps every
 * message it is given and answers with a made-up id, or with the failures it
 * was told to give (failNext()). Its logs and suppression lists hold what it
 * was given (log(), suppress()), its domain counts what it was told
 * (count()), and its reads can be made to fail
 * (failReads()). Select it with campaigns.provider "fake"; it is a
 * singleton, so a test reads what was sent from the container. It keeps
 * every message for the life of the process, so it suits tests and short
 * local runs, not a long-running worker.
 */
class FakeProvider implements CampaignProvider
{
    /** @var list<OutgoingMessage> */
    protected array $sent = [];

    /** @var list<SendFailed> */
    protected array $failures = [];

    /** @var list<Feedback> */
    protected array $logged = [];

    /** @var list<Suppression> */
    protected array $suppressed = [];

    protected ?CampaignsException $readFailure = null;

    protected int $logDays = 5;

    /** @var array<string, array{sent: int, forwarded: int, hard_bounces: int, complaints: int, unsubscribes: int}> */
    protected array $metrics = [];

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

    /**
     * Events the provider's logs hold, read back by eventsForMessage() and
     * eventsBetween().
     */
    public function log(Feedback ...$events): static
    {
        $this->logged = [...$this->logged, ...array_values($events)];

        return $this;
    }

    /**
     * Addresses the provider holds on its suppression lists, on every domain.
     */
    public function suppress(Suppression ...$suppressions): static
    {
        $this->suppressed = [...$this->suppressed, ...array_values($suppressions)];

        return $this;
    }

    /**
     * Every read of the logs or suppression lists throws this until it is
     * given null.
     */
    public function failReads(?CampaignsException $exception = null): static
    {
        $this->readFailure = $exception;

        return $this;
    }

    public function keepLogsFor(int $days): static
    {
        $this->logDays = $days;

        return $this;
    }

    /**
     * What the provider counted on a UTC date (Y-m-d), on every domain; the
     * counts not given are 0.
     *
     * @param  array<string, int>  $counts
     */
    public function count(string $date, array $counts): static
    {
        $this->metrics[$date] = [...array_fill_keys(DomainMetrics::COUNTS, 0), ...array_intersect_key($counts, array_flip(DomainMetrics::COUNTS))];
        ksort($this->metrics);

        return $this;
    }

    public function domainMetrics(string $domain, CarbonInterface $from, CarbonInterface $to): DomainMetrics
    {
        $this->throwReadFailure();

        $days = [];
        $last = $to->copy()->utc()->startOfDay();

        for ($day = $from->copy()->utc()->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[$day->toDateString()] = $this->metrics[$day->toDateString()] ?? array_fill_keys(DomainMetrics::COUNTS, 0);
        }

        /** @var array<string, array{sent: int, forwarded: int, hard_bounces: int, complaints: int, unsubscribes: int}> $days */
        return new DomainMetrics($days);
    }

    public function verifyWebhook(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): array
    {
        return [];
    }

    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
    {
        $this->throwReadFailure();

        return array_values(array_filter(
            $this->logged,
            fn (Feedback $event): bool => $event->messageUuid === $messageUuid && $event->occurredAt->greaterThanOrEqualTo($since),
        ));
    }

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        $this->throwReadFailure();

        $events = array_filter(
            $this->logged,
            fn (Feedback $event): bool => $event->occurredAt->betweenIncluded($from, $to),
        );

        usort($events, fn (Feedback $a, Feedback $b): int => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    public function logDays(): int
    {
        return $this->logDays;
    }

    public function suppressions(string $domain): iterable
    {
        $this->throwReadFailure();

        return $this->suppressed;
    }

    public function suppressionsFor(string $domain, string $email): array
    {
        $this->throwReadFailure();

        return array_values(array_filter(
            $this->suppressed,
            fn (Suppression $suppression): bool => strtolower($suppression->email) === strtolower($email),
        ));
    }

    public function tracks(): bool
    {
        return true;
    }

    protected function throwReadFailure(): void
    {
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
    }
}
