<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\ReconcileCampaignsAction;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Sending\FakeProvider;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Sending\MailgunProvider;
use Mozex\Campaigns\Sending\ReconcileResult;

/**
 * The fake provider, keeping what it was asked.
 */
class RecordingProvider extends FakeProvider
{
    /** @var list<array{string, CarbonInterface}> */
    public array $lookedUp = [];

    /** @var list<array{string, CarbonInterface, CarbonInterface}> */
    public array $replayed = [];

    public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
    {
        $this->lookedUp[] = [$messageUuid, $since];

        return parent::eventsForMessage($messageUuid, $since);
    }

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        $this->replayed[] = [$domain, $from, $to];

        return parent::eventsBetween($domain, $from, $to);
    }
}

/**
 * Logs whose replay gives the events it holds, then fails.
 */
class FailingPartWayProvider extends FakeProvider
{
    public bool $fails = true;

    public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
    {
        foreach (parent::eventsBetween($domain, $from, $to) as $i => $event) {
            if ($this->fails && $i === 150) {
                throw CampaignsException::providerUnreadable('Mailgun answered 503 when its logs or suppressions were read.');
            }

            yield $event;
        }
    }
}

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
});

function reconcile(): ReconcileResult
{
    return app(ReconcileCampaignsAction::class)->execute();
}

function recordingProvider(): RecordingProvider
{
    $provider = new RecordingProvider;
    app()->instance(CampaignProvider::class, $provider);

    return $provider;
}

/**
 * An event the provider's logs hold about a message.
 *
 * @param  array<string, mixed>  $changes
 */
function loggedEvent(CampaignMessage $message, FeedbackType $type, CarbonInterface $at, array $changes = []): Feedback
{
    return new Feedback(...[
        'providerEventId' => Str::random(22),
        'type' => $type,
        'occurredAt' => $at,
        'recipient' => (string) $message->email,
        'messageUuid' => $message->uuid,
        ...$changes,
    ]);
}

/**
 * A campaign's message whose send ended unclear, at the given time.
 */
function unclearMessage(Campaign $campaign, int $index, MessageStatus $status, CarbonInterface $sendingAt): CampaignMessage
{
    $message = $campaign->messages()->orderBy('position')->skip($index)->firstOrFail();
    $message->forceFill(['status' => $status, 'claim_id' => (string) Str::uuid(), 'claimed_at' => $sendingAt, 'sending_at' => $sendingAt, 'failure_reason' => 'unclear', 'failure_code' => 504])->save();

    return $message;
}

describe('queued messages whose job was lost', function (): void {
    it('puts them back to pending once their claim is past the send job\'s limit, sending nothing, and the pump claims them again', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(3);
        Queue::fake([SendMessageJob::class]);

        app(PumpCampaignsAction::class)->execute();
        $jobs = Queue::pushed(SendMessageJob::class);
        $this->travel(SendMessageJob::CLAIM_EXPIRES - 60)->seconds();

        expect(reconcile()->released)->toBe(0)
            ->and($campaign->messages()->where('status', MessageStatus::Queued)->count())->toBe(3);

        $this->travel(61)->seconds();

        expect(reconcile()->released)->toBe(3);

        // The lost jobs turning up after all send nothing.
        foreach ($jobs as $job) {
            app()->call([$job, 'handle']);
        }

        $messages = $campaign->messages()->get();

        expect($messages->pluck('status')->unique()->all())->toBe([MessageStatus::Pending])
            ->and($messages->whereNotNull('claim_id')->count() + $messages->whereNotNull('claimed_at')->count() + $messages->whereNotNull('token_hash')->count())->toBe(0)
            ->and(fakeProvider()->sent())->toBe([])
            ->and(app(PumpCampaignsAction::class)->execute()->claimed)->toBe([$campaign->id => 3]);
    })->with('databases');
});

it('queues again an event left unprocessed for 15 minutes, for a day at most, a click after its delay', function (): void {
    Queue::fake([ProcessFeedbackJob::class]);

    $waiting = ProviderEvent::factory()->create(['created_at' => now()->subMinutes(16)]);
    $click = ProviderEvent::factory()->create(['type' => FeedbackType::Clicked, 'created_at' => now()->subHours(23)]);
    ProviderEvent::factory()->create(['created_at' => now()->subMinutes(14)]);
    ProviderEvent::factory()->create(['created_at' => now()->subHours(25)]);
    ProviderEvent::factory()->create(['created_at' => now()->subHour(), 'processed_at' => now()->subHour()]);

    expect(reconcile()->requeued)->toBe(2);

    Queue::assertPushed(ProcessFeedbackJob::class, 2);
    Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $waiting->id && $job->delay === null);
    Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $click->id && $job->delay === ProcessFeedbackJob::CLICK_DELAY);
});

describe('unclear sends', function (): void {
    it('marks one sent from the events the provider\'s logs hold, never sending it again', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(1);
        $message = unclearMessage($campaign, 0, MessageStatus::Unknown, now()->subMinutes(20));
        $provider = recordingProvider();
        $provider->log(
            loggedEvent($message, FeedbackType::Accepted, now()->subMinutes(20)),
            loggedEvent($message, FeedbackType::Delivered, now()->subMinutes(19)),
        );

        $result = reconcile();
        $message->refresh();

        expect($result->found)->toBe(1)
            ->and($result->lost)->toBe(0)
            ->and($provider->lookedUp)->toHaveCount(1)
            ->and($provider->lookedUp[0][1]->toDateTimeString())->toBe(now()->subMinutes(25)->toDateTimeString())
            ->and($message->status)->toBe(MessageStatus::Delivered)
            ->and($message->sent_at?->toDateTimeString())->toBe(now()->subMinutes(20)->toDateTimeString())
            ->and($message->failure_reason)->toBeNull()
            ->and($message->subscriber?->first_mailed_at)->not->toBeNull()
            ->and($message->events()->count())->toBe(2)
            ->and($provider->sent())->toBe([]);

        expect(reconcile()->found)->toBe(0)
            ->and($provider->lookedUp)->toHaveCount(1);
    })->with('databases');

    it('fails as never taken one the logs hold nothing for an hour after it went, and only then', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(7);
        $lost = unclearMessage($campaign, 0, MessageStatus::Unknown, now()->subMinutes(61));
        $sending = unclearMessage($campaign, 1, MessageStatus::Sending, now()->subMinutes(61));
        $recent = unclearMessage($campaign, 2, MessageStatus::Unknown, now()->subMinutes(30));
        $awaitingItsEvent = unclearMessage($campaign, 3, MessageStatus::Unknown, now()->subMinutes(61));
        $beyondTheLogs = unclearMessage($campaign, 4, MessageStatus::Unknown, now()->subDays(5)->subMinute());
        // Inside the logs' last hour, which a lookup may only partly see.
        $atTheEdge = unclearMessage($campaign, 5, MessageStatus::Sending, now()->subDays(5)->addMinutes(30));
        // Its job may still be running.
        unclearMessage($campaign, 6, MessageStatus::Sending, now()->subMinutes(14));
        ProviderEvent::factory()->for($awaitingItsEvent, 'message')->create(['created_at' => now()]);
        $provider = recordingProvider();

        $result = reconcile();

        expect($result->found)->toBe(0)
            ->and($result->lost)->toBe(2)
            ->and(array_column($provider->lookedUp, 0))->toEqualCanonicalizing([$lost->uuid, $sending->uuid, $recent->uuid, $awaitingItsEvent->uuid])
            ->and($lost->fresh())
            ->status->toBe(MessageStatus::Failed)
            ->failure_reason->toBe('not_accepted')
            ->failure_code->toBe(504)
            ->sent_at->toBeNull()
            ->failed_at->not->toBeNull()
            ->and($sending->fresh()->status)->toBe(MessageStatus::Failed)
            ->and($recent->fresh()->status)->toBe(MessageStatus::Unknown)
            ->and($awaitingItsEvent->fresh()->status)->toBe(MessageStatus::Unknown)
            ->and($beyondTheLogs->fresh()->status)->toBe(MessageStatus::Unknown)
            // No longer sending, so its campaign can complete.
            ->and($atTheEdge->fresh())
            ->status->toBe(MessageStatus::Unknown)
            ->failure_reason->toBe('unclear')
            ->and(app(PumpCampaignsAction::class)->execute()->claimed)->toBe([])
            ->and($provider->sent())->toBe([]);
    })->with('databases');

    it('fails nothing when the provider can\'t be read, reporting it once an hour, and a bug each time', function (): void {
        Exceptions::fake();
        $campaign = sendingCampaign(1);
        $message = unclearMessage($campaign, 0, MessageStatus::Unknown, now()->subHours(2));
        fakeProvider()->failReads(CampaignsException::missingMailgunApiKey());

        $this->artisan('campaigns:reconcile')
            ->expectsOutputToContain('CAMPAIGNS_MAILGUN_API_KEY')
            ->assertFailed();

        expect(reconcile()->problems)->toHaveCount(1)
            ->and($message->fresh()->status)->toBe(MessageStatus::Unknown);

        Exceptions::assertReportedCount(1);

        $this->travel(61)->minutes();
        reconcile();

        Exceptions::assertReportedCount(2);

        $provider = new class extends FakeProvider
        {
            public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
            {
                throw new RuntimeException('A bug.');
            }
        };
        app()->instance(CampaignProvider::class, $provider);

        expect(reconcile()->problems)->toBe(['Reconciling failed: RuntimeException.'])
            ->and(reconcile()->problems)->toHaveCount(1)
            ->and($message->fresh()->status)->toBe(MessageStatus::Unknown);

        Exceptions::assertReportedCount(4);

        app()->instance(CampaignProvider::class, new FakeProvider);

        $this->artisan('campaigns:reconcile')
            ->expectsOutputToContain('found 0 unclear messages there and failed 1 it never took')
            ->assertSuccessful();
    });

    it('reports an unreadable provider even when the cache can\'t be reached, and goes on', function (): void {
        Exceptions::fake();
        $campaign = sendingCampaign(1);
        unclearMessage($campaign, 0, MessageStatus::Unknown, now()->subHours(2));
        fakeProvider()->failReads(CampaignsException::missingMailgunApiKey());
        config()->set('campaigns.cache_store', 'missing');
        ProviderEvent::factory()->create(['created_at' => now()->subMinutes(16)]);
        Queue::fake([ProcessFeedbackJob::class]);

        $result = reconcile();

        expect($result->problems)->toHaveCount(1)
            ->and($result->requeued)->toBe(1);

        Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), 'CAMPAIGNS_MAILGUN_API_KEY'));
    });

    it('leaves one the logs can\'t tell about, and goes on past one whose events fail to store', function (): void {
        Exceptions::fake();
        $campaign = sendingCampaign(3);
        $untold = unclearMessage($campaign, 0, MessageStatus::Unknown, now()->subHours(3));
        $broken = unclearMessage($campaign, 1, MessageStatus::Unknown, now()->subHours(2));
        $lost = unclearMessage($campaign, 2, MessageStatus::Unknown, now()->subHours(2)->addMinute());

        $provider = new class($untold->uuid) extends FakeProvider
        {
            public function __construct(public string $untold) {}

            public function eventsForMessage(string $messageUuid, CarbonInterface $since): ?array
            {
                return $messageUuid === $this->untold ? null : parent::eventsForMessage($messageUuid, $since);
            }
        };
        $provider->log(loggedEvent($broken, FeedbackType::Delivered, now()->subHours(2)));
        app()->instance(CampaignProvider::class, $provider);

        app()->instance(RecordFeedbackAction::class, new class extends RecordFeedbackAction
        {
            public function execute(Feedback $feedback): FeedbackResult
            {
                throw new RuntimeException('Storing broke.');
            }
        });

        $result = reconcile();

        expect($result->problems)->toBe(['Reconciling failed: RuntimeException.'])
            ->and($result->lost)->toBe(1)
            ->and($untold->fresh()->status)->toBe(MessageStatus::Unknown)
            ->and($broken->fresh()->status)->toBe(MessageStatus::Unknown)
            ->and($lost->fresh()->status)->toBe(MessageStatus::Failed);
    });
});

describe('the replay', function (): void {
    it('stores and applies what no webhook brought, from just before a campaign started to half an hour ago, then from where it stopped', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(3);
        app(PumpCampaignsAction::class)->execute();
        [$first, $second, $third] = $campaign->messages()->orderBy('position')->get()->all();
        $sentAt = now()->copy();
        $this->travel(2)->hours();

        $provider = recordingProvider();
        $stored = loggedEvent($second, FeedbackType::Delivered, $sentAt->copy()->addMinute());
        ProviderEvent::factory()->for($second, 'message')->create(['provider_event_id' => $stored->providerEventId, 'processed_at' => now()]);
        $provider->log(
            loggedEvent($first, FeedbackType::Delivered, $sentAt->copy()->addMinute()),
            $stored,
            loggedEvent($third, FeedbackType::Opened, now()->subMinutes(10)),
        );

        expect(reconcile()->replayed)->toBe(1)
            ->and($provider->replayed)->toHaveCount(1)
            ->and($provider->replayed[0][0])->toBe('news.example.com')
            ->and($provider->replayed[0][1]->toDateTimeString())->toBe($campaign->fresh()->started_at?->subMinutes(5)->toDateTimeString())
            ->and($provider->replayed[0][2]->toDateTimeString())->toBe(now()->subMinutes(30)->toDateTimeString())
            ->and($first->fresh()->status)->toBe(MessageStatus::Delivered)
            ->and($second->events()->count())->toBe(1)
            ->and($third->fresh()->opened_at)->toBeNull()
            ->and($campaign->fresh()->events_read_until?->toDateTimeString())->toBe(now()->subMinutes(30)->toDateTimeString());

        $bookmark = now()->subMinutes(30);
        $updatedAt = $campaign->fresh()->updated_at;
        $this->travel(1)->hours();

        // Before the bookmark: a run read that stretch already.
        $provider->log(loggedEvent($second, FeedbackType::Clicked, $bookmark->copy()->subMinute(), ['url' => 'https://example.com/']));

        expect(reconcile()->replayed)->toBe(1)
            ->and($provider->replayed[1][1]->toDateTimeString())->toBe($bookmark->toDateTimeString())
            ->and($third->fresh()->opened_at)->not->toBeNull()
            ->and($second->fresh()->clicked_at)->toBeNull()
            ->and($campaign->fresh()->updated_at?->toDateTimeString())->toBe($updatedAt?->toDateTimeString());
    })->with('databases');

    it('reads each domain with a campaign that may still get events, from its earliest bookmark, and advances them all', function (): void {
        $provider = recordingProvider();
        $news = fn (array $attributes): Campaign => Campaign::factory()->create(['status' => CampaignStatus::Sending, 'started_at' => now()->subDays(2), ...$attributes]);

        $behind = $news(['events_read_until' => now()->subHours(3)]);
        $ahead = $news(['status' => CampaignStatus::Paused, 'events_read_until' => now()->subHour()]);
        $finished = $news(['from_email' => 'team@other.example.com', 'status' => CampaignStatus::Completed, 'completed_at' => now()->subDays(4)]);
        $news(['from_email' => 'team@old.example.com', 'status' => CampaignStatus::Completed, 'completed_at' => now()->subDays(6)]);
        $news(['from_email' => 'team@gone.example.com', 'status' => CampaignStatus::Canceled, 'canceled_at' => now()->subDays(6)]);
        $news(['from_email' => 'team@later.example.com', 'status' => CampaignStatus::Scheduled, 'started_at' => null]);
        $news(['from_email' => 'team@draft.example.com', 'status' => CampaignStatus::Draft, 'started_at' => null]);

        reconcile();

        expect(array_map(fn (array $asked): array => [$asked[0], $asked[1]->toDateTimeString()], $provider->replayed))->toEqualCanonicalizing([
            ['news.example.com', now()->subHours(3)->toDateTimeString()],
            ['other.example.com', now()->subDays(2)->subMinutes(5)->toDateTimeString()],
        ])
            ->and(array_map(fn (Campaign $campaign): ?string => $campaign->fresh()?->events_read_until?->toDateTimeString(), [$behind, $ahead, $finished]))
            ->toBe(array_fill(0, 3, now()->subMinutes(30)->toDateTimeString()));

        // A domain whose logs fail leaves the others to be read.
        $this->travel(1)->hours();
        $failing = new class extends RecordingProvider
        {
            public function eventsBetween(string $domain, CarbonInterface $from, CarbonInterface $to): iterable
            {
                if ($domain === 'news.example.com') {
                    throw CampaignsException::providerUnreadable('Mailgun answered 404 when its logs or suppressions were read.');
                }

                return parent::eventsBetween($domain, $from, $to);
            }
        };
        app()->instance(CampaignProvider::class, $failing);

        expect(reconcile()->problems)->toBe(['Mailgun answered 404 when its logs or suppressions were read.'])
            ->and($finished->fresh()?->events_read_until?->toDateTimeString())->toBe(now()->subMinutes(30)->toDateTimeString())
            ->and($behind->fresh()?->events_read_until?->toDateTimeString())->toBe(now()->subMinutes(90)->toDateTimeString());
    });

    it('stores a run\'s share and starts the next run where it stopped, its bookmark in the app\'s timezone', function (string $driver): void {
        useDatabase($driver);
        $zone = date_default_timezone_get();
        config()->set('app.timezone', 'America/New_York');
        date_default_timezone_set('America/New_York');

        try {
            Queue::fake([ProcessFeedbackJob::class]);
            $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending, 'started_at' => now()->subDays(1)]);
            sends($campaign, 1100, ['status' => MessageStatus::Sent->value, 'delivered_at' => null], now()->subHours(20));
            $provider = recordingProvider();

            // In UTC, as Mailgun's times are read.
            $campaign->messages()->orderBy('id')->get()->each(fn (CampaignMessage $message, int $i) => $provider->log(
                loggedEvent($message, FeedbackType::Delivered, now()->utc()->subHours(19)->addSeconds($i)),
            ));

            expect(reconcile()->replayed)->toBe(ReconcileCampaignsAction::REPLAY_STORED)
                ->and($campaign->fresh()->events_read_until?->toDateTimeString())->toBe(now()->subHours(19)->addSeconds(ReconcileCampaignsAction::REPLAY_STORED - 1)->toDateTimeString())
                ->and(reconcile()->replayed)->toBe(100)
                ->and(ProviderEvent::query()->count())->toBe(1100)
                ->and($campaign->fresh()->events_read_until?->toDateTimeString())->toBe(now()->subMinutes(30)->toDateTimeString());
        } finally {
            config()->set('app.timezone', $zone);
            date_default_timezone_set($zone);
        }
    })->with('databases');

    it('keeps what it stored when the logs fail part way, and the next run goes on from there', function (): void {
        Queue::fake([ProcessFeedbackJob::class]);
        $campaign = Campaign::factory()->create(['status' => CampaignStatus::Sending, 'started_at' => now()->subDays(1)]);
        sends($campaign, 200, ['status' => MessageStatus::Sent->value, 'delivered_at' => null], now()->subHours(20));
        $provider = new FailingPartWayProvider;
        app()->instance(CampaignProvider::class, $provider);

        $campaign->messages()->orderBy('id')->get()->each(fn (CampaignMessage $message, int $i) => $provider->log(
            loggedEvent($message, FeedbackType::Delivered, now()->subHours(19)->addSeconds($i)),
        ));

        $result = reconcile();

        expect($result->replayed)->toBe(100)
            ->and($result->problems)->toBe(['Mailgun answered 503 when its logs or suppressions were read.'])
            ->and($campaign->fresh()->events_read_until?->toDateTimeString())->toBe(now()->subHours(19)->addSeconds(99)->toDateTimeString());

        $provider->fails = false;

        expect(reconcile()->replayed)->toBe(100)
            ->and(ProviderEvent::query()->count())->toBe(200);
    });
});

it('settles an unclear send and a missed delivery from Mailgun\'s Logs API as it answers', function (string $driver): void {
    useDatabase($driver);
    $campaign = sendingCampaign(2);
    app(PumpCampaignsAction::class)->execute();
    [$delivered, $unclear] = $campaign->messages()->orderBy('position')->get()->all();
    $this->travel(2)->hours();
    $unclear = unclearMessage($campaign, 1, MessageStatus::Unknown, now()->subMinutes(20));

    config()->set('campaigns.mailgun.api_key', 'key-reader');
    app()->instance(CampaignProvider::class, app(MailgunProvider::class));
    Http::preventStrayRequests();
    Http::fake(['api.mailgun.net/v1/analytics/logs' => fn (ClientRequest $request): PromiseInterface => Http::response(match ($request['filter']['AND'][0]['attribute']) {
        'user_variables' => mailgunLogs([mailgunLogItem('accepted', [
            'timestamp' => now()->subMinutes(20)->getTimestamp(),
            'user-variables' => ['campaign-message' => $unclear->uuid],
        ])]),
        'domain' => mailgunLogs([mailgunLogItem('delivered', [
            'timestamp' => now()->subHours(2)->addMinute()->getTimestamp(),
            'user-variables' => ['campaign-message' => $delivered->uuid],
        ])]),
    })]);

    $result = reconcile();

    expect($result->replayed)->toBe(1)
        ->and($result->found)->toBe(1)
        ->and($result->problems)->toBe([])
        ->and($delivered->fresh()->status)->toBe(MessageStatus::Delivered)
        ->and($unclear->fresh()->status)->toBe(MessageStatus::Sent);
})->with('databases');
