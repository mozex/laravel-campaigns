<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending\Drivers;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Sending\DomainMetrics;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Sending\Suppression;

/**
 * What the fake drivers record and answer, shared so a test can pick the
 * abilities a driver has: whether one has an ability is whether its class
 * implements the ability's interface, which can't change at run time. It
 * keeps every message it is given and answers with a made-up id, or with
 * the failures it was told to give (failNext()); its webhooks carry what it
 * was told to post (webhook()), its logs and suppression lists hold what it
 * was given (log(), suppress()), its domain counts what it was told
 * (count()), and its reads can be made to fail (failReads()). It keeps
 * every message for the life of the process, so it suits tests and short
 * local runs, not a long-running worker.
 */
trait FakesDriver
{
    /** @var list<OutgoingMessage> */
    protected array $sent = [];

    /** @var list<SendFailed> */
    protected array $failures = [];

    /** @var list<Feedback> */
    protected array $webhooks = [];

    /** @var list<Feedback> */
    protected array $logged = [];

    /** @var list<Suppression> */
    protected array $suppressed = [];

    protected ?CampaignsException $readFailure = null;

    protected int $logDays = 5;

    /** @var array<string, array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int}> */
    protected array $metrics = [];

    /**
     * Refused in production, for the whole domain: it would mark a campaign
     * sent with nothing delivered, and drop every double opt-in email.
     */
    public function send(OutgoingMessage $message): SendReceipt
    {
        if (app()->isProduction()) {
            throw new SendFailed(SendFailure::DomainRefused, 'The fake driver sends nothing, which production never sends through: set campaigns.driver (CAMPAIGNS_DRIVER) to a driver that delivers.');
        }

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
     * Events the next webhook request carries. Only a request made after
     * this is verified, so the fake never takes a stranger's post.
     */
    public function webhook(Feedback ...$events): static
    {
        $this->webhooks = [...$this->webhooks, ...array_values($events)];

        return $this;
    }

    public function verifyWebhook(Request $request): bool
    {
        return $this->webhooks !== [];
    }

    /**
     * @return list<Feedback>
     */
    public function parseWebhook(Request $request): array
    {
        $events = $this->webhooks;
        $this->webhooks = [];

        return $events;
    }

    /**
     * Events the service's logs hold, read back by eventsForMessage() and
     * eventsBetween().
     */
    public function log(Feedback ...$events): static
    {
        $this->logged = [...$this->logged, ...array_values($events)];

        return $this;
    }

    /**
     * @return ?list<Feedback>
     */
    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
    {
        $this->throwReadFailure();

        return array_values(array_filter(
            $this->logged,
            fn (Feedback $event): bool => $event->messageUuid === $messageUuid && $event->occurredAt->greaterThanOrEqualTo($since),
        ));
    }

    /**
     * @return iterable<Feedback>
     */
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

    public function keepLogsFor(int $days): static
    {
        $this->logDays = $days;

        return $this;
    }

    public function logDays(): int
    {
        return $this->logDays;
    }

    /**
     * Addresses the service holds on its suppression lists, on every domain.
     */
    public function suppress(Suppression ...$suppressions): static
    {
        $this->suppressed = [...$this->suppressed, ...array_values($suppressions)];

        return $this;
    }

    /**
     * @return iterable<Suppression>
     */
    public function suppressions(string $domain): iterable
    {
        $this->throwReadFailure();

        return $this->suppressed;
    }

    /**
     * @return list<Suppression>
     */
    public function suppressionsFor(string $domain, string $email): array
    {
        $this->throwReadFailure();

        return array_values(array_filter(
            $this->suppressed,
            fn (Suppression $suppression): bool => strtolower($suppression->email) === strtolower($email),
        ));
    }

    /**
     * What the service counted on a UTC date (Y-m-d), on every domain; the
     * counts not given are 0, and a count given as null isn't kept.
     *
     * @param  array<string, ?int>  $counts
     */
    public function count(string $date, array $counts): static
    {
        /** @var array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int} $day */
        $day = [...array_fill_keys(DomainMetrics::COUNTS, 0), ...array_intersect_key($counts, array_flip(DomainMetrics::COUNTS))];
        $this->metrics[$date] = $day;
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

        /** @var array<string, array{sent: ?int, forwarded: ?int, hard_bounces: ?int, complaints: ?int, unsubscribes: ?int}> $days */
        return new DomainMetrics($days);
    }

    /**
     * Every read of the logs, suppression lists or metrics throws this (by
     * default, the error an unreadable service gives) until readsWork().
     */
    public function failReads(?CampaignsException $exception = null): static
    {
        $this->readFailure = $exception ?? CampaignsException::providerUnreadable('Reads are failed by the fake driver\'s failReads().');

        return $this;
    }

    public function readsWork(): static
    {
        $this->readFailure = null;

        return $this;
    }

    protected function throwReadFailure(): void
    {
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
    }
}
