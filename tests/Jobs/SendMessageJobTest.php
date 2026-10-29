<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\FakeProvider;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\SendFailed;
use Mozex\Campaigns\Sending\SendReceipt;
use Mozex\Campaigns\Support\Token;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
});

/**
 * Claims the campaign's next messages and returns their send jobs, unrun.
 *
 * @return list<SendMessageJob>
 */
function claimedJobs(): array
{
    // Only the send jobs: a campaign started after this still freezes.
    Queue::fake([SendMessageJob::class]);
    app(PumpCampaignsAction::class)->execute();

    return Queue::pushed(SendMessageJob::class)->values()->all();
}

function runJob(SendMessageJob $job): void
{
    app()->call([$job, 'handle']);
}

it('sends a claimed message once, and nothing for a duplicate or another claim', function (string $driver): void {
    useDatabase($driver);
    $campaign = sendingCampaign(1);
    [$job] = claimedJobs();

    runJob(new SendMessageJob($job->messageId, (string) Str::uuid()));

    expect(fakeProvider()->sent())->toBe([])
        ->and(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Queued)
        ->claim_id->toBe($job->claimId);

    runJob($job);
    runJob($job);

    $message = CampaignMessage::query()->sole();
    [$sent] = fakeProvider()->sent();
    $token = Str::after($sent->unsubscribeUrl, '/campaigns/u/');

    expect(fakeProvider()->sent())->toHaveCount(1)
        ->and($sent)
        ->uuid->toBe($message->uuid)
        ->to->toBe('news-1@example.org')
        ->tag->toBe('news')
        ->and($message)
        ->status->toBe(MessageStatus::Sent)
        ->provider_message_id->toBe("fake.{$message->uuid}@campaigns.test")
        ->sent_at->toEqual(now())
        ->token_hash->toBe(Token::hash($token))
        ->and($message->subscriber->first_mailed_at)->toEqual(now())
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

    // A later campaign to the same list keeps the address's first email date.
    $this->travel(1)->days();
    sendingCampaign([], key: 'later');
    runJob(claimedJobs()[0]);

    expect($message->subscriber->fresh()->first_mailed_at)->toEqual(now()->subDay());
})->with('databases');

it('skips a message whose person can no longer get it', function (Closure $change, string $driver): void {
    useDatabase($driver);
    $campaign = sendingCampaign(['reader@example.org']);
    [$job] = claimedJobs();

    $change(Subscriber::query()->sole(), $campaign);
    runJob($job);

    expect(fakeProvider()->sent())->toBe([])
        ->and(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Skipped)
        ->token_hash->toBeNull();
})->with([
    'opted out' => [fn (Subscriber $subscriber) => $subscriber->update(['opted_out_at' => now()])],
    'blocked' => [fn (Subscriber $subscriber) => $subscriber->update(['blocked_at' => now(), 'blocked_reason' => BlockReason::Complaint])],
    'sunset' => [fn (Subscriber $subscriber) => $subscriber->update(['first_mailed_at' => now()->subMonths(13)])],
    'off its lists' => [fn (Subscriber $subscriber) => $subscriber->subscriptions()->delete()],
    'no longer subscribed on its lists' => [fn (Subscriber $subscriber) => $subscriber->subscriptions()->update(['status' => SubscriptionStatus::Unsubscribed])],
    'moved to another address' => [fn (Subscriber $subscriber) => $subscriber->update(['email' => 'moved@example.org'])],
    'deleted' => [fn (Subscriber $subscriber) => $subscriber->delete()],
    'left with its email gone' => [fn (Subscriber $subscriber, Campaign $campaign) => $campaign->update(['frozen_revision_id' => null])],
])->with('databases');

it('puts a message back when the provider took nothing, and halves the campaign\'s rate for ten minutes', function (string $driver): void {
    useDatabase($driver);
    $campaign = sendingCampaign(10, ['per_minute' => 4]);
    $jobs = claimedJobs();
    fakeProvider()->failNext(new SendFailed(SendFailure::NotAccepted, 'Mailgun answered 429.', 429));

    foreach ($jobs as $job) {
        runJob($job);
    }

    expect(fakeProvider()->sent())->toHaveCount(3)
        ->and(CampaignMessage::query()->find($jobs[0]->messageId))
        ->status->toBe(MessageStatus::Pending)
        ->claim_id->toBeNull()
        ->claimed_at->toBeNull()
        ->sending_at->toBeNull()
        ->token_hash->toBeNull()
        ->failure_reason->toBeNull()
        ->and($campaign->fresh()->rate_halved_until)->toEqual(now()->addMinutes(10));

    $this->travel(1)->minutes();
    $halved = claimedJobs();
    array_map(runJob(...), $halved);
    $this->travel(10)->minutes();

    expect($halved)->toHaveCount(2)
        ->and(claimedJobs())->toHaveCount(4);
})->with('databases');

it('pauses every campaign on the domain the provider refuses, and only those', function (string $driver): void {
    useDatabase($driver);
    $news = sendingCampaign(2);
    $promo = sendingCampaign(1, key: 'promo', list: 'promo-readers', at: now()->addDay());
    $other = Campaign::factory()->sending()->create(['from_email' => 'team@other.example.net']);
    CampaignMessage::factory()->for($other)->create();
    [$job] = claimedJobs();
    fakeProvider()->failNext(new SendFailed(SendFailure::DomainRefused, 'Mailgun answered 401: Forbidden', 401));
    Event::fake([CampaignPausedEvent::class]);

    runJob($job);

    expect(CampaignMessage::query()->find($job->messageId)->status)->toBe(MessageStatus::Pending)
        ->and($news->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toBe('The provider refused the sending domain news.example.com: Mailgun answered 401: Forbidden')
        ->and($promo->fresh()->status)->toBe(CampaignStatus::Paused)
        ->and($other->fresh()->status)->toBe(CampaignStatus::Sending);

    Event::assertDispatchedTimes(CampaignPausedEvent::class, 2);
    Event::assertDispatched(CampaignPausedEvent::class, fn (CampaignPausedEvent $event): bool => $event->automatic);
})->with('databases');

it('leaves a message unknown when the provider may have it', function (string $driver): void {
    useDatabase($driver);
    sendingCampaign(1);
    [$job] = claimedJobs();
    fakeProvider()->failNext(new SendFailed(SendFailure::Unclear, 'Mailgun answered 504.', 504));

    runJob($job);

    expect(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Unknown)
        ->failure_reason->toBe('unclear')
        ->failure_code->toBe(504)
        ->token_hash->not->toBeNull()
        ->and(Subscriber::query()->sole()->first_mailed_at)->toBeNull()
        // One unclear send slows the campaign; it takes a run of them to pause it.
        ->and(Campaign::query()->sole())
        ->rate_halved_until->toEqual(now()->addMinutes(10))
        ->status->toBe(CampaignStatus::Sending);
})->with('databases');

it('pauses a campaign whose sends keep ending unclear, before it spends its audience', function (): void {
    $campaign = sendingCampaign(SendMessageJob::UNCLEAR_LIMIT + 2, ['per_minute' => SendMessageJob::UNCLEAR_LIMIT + 2]);
    $jobs = claimedJobs();
    fakeProvider()->failNext(...array_fill(0, SendMessageJob::UNCLEAR_LIMIT, new SendFailed(SendFailure::Unclear, 'Connection lost.')));
    Event::fake([CampaignPausedEvent::class]);

    array_map(runJob(...), $jobs);

    expect(CampaignMessage::query()->where('status', MessageStatus::Unknown)->count())->toBe(SendMessageJob::UNCLEAR_LIMIT)
        // The rest go back to waiting rather than out.
        ->and(fakeProvider()->sent())->toBe([])
        ->and(CampaignMessage::query()->where('status', MessageStatus::Pending)->count())->toBe(2)
        ->and($campaign->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toStartWith(SendMessageJob::UNCLEAR_LIMIT.' sends in the last 5 minutes ended without a clear answer');

    Event::assertDispatched(CampaignPausedEvent::class, fn (CampaignPausedEvent $event): bool => $event->automatic);
});

it('puts a queued message back, sending nothing', function (Closure $arrange): void {
    sendingCampaign(1);
    [$job] = claimedJobs();
    $arrange();

    runJob($job);

    expect(fakeProvider()->sent())->toBe([])
        ->and(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Pending)
        ->claim_id->toBeNull()
        ->claimed_at->toBeNull();
})->with([
    'while sending is switched off' => [fn () => config()->set('campaigns.enabled', false)],
    'when its claim went stale waiting for a worker' => [fn () => test()->travel(SendMessageJob::CLAIM_EXPIRES + 1)->seconds()],
]);

it('marks a message sent that was taken for unknown while its send went on', function (): void {
    sendingCampaign(1);
    [$job] = claimedJobs();
    app()->instance(CampaignProvider::class, new class extends FakeProvider
    {
        public function send(OutgoingMessage $message): SendReceipt
        {
            // What failed() does when the queue gives up on a slow job.
            CampaignMessage::query()->where('uuid', $message->uuid)->update(['status' => MessageStatus::Unknown]);

            return parent::send($message);
        }
    });

    runJob($job);

    expect(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Sent)
        ->failure_reason->toBeNull();
});

it('keeps the provider\'s id on a message its events moved on before its send job finished', function (): void {
    sendingCampaign(1);
    [$job] = claimedJobs();
    $provider = new class extends FakeProvider
    {
        public ?string $id = null;

        public function send(OutgoingMessage $message): SendReceipt
        {
            $receipt = parent::send($message);
            $this->id = $receipt->providerMessageId;

            // Delivered and applied while the answer was on its way back,
            // reported by the message's uuid alone.
            $event = ProviderEvent::factory()->create([
                'message_id' => CampaignMessage::query()->where('uuid', $message->uuid)->value('id'),
                'type' => FeedbackType::Delivered,
            ]);
            app()->call([new ProcessFeedbackJob($event->id), 'handle']);

            return $receipt;
        }
    };
    app()->instance(CampaignProvider::class, $provider);

    runJob($job);

    expect(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Delivered)
        ->provider_message_id->toBe($provider->id)
        ->sent_at->not->toBeNull()
        ->and($provider->id)->not->toBeNull();
});

it('settles a message whose job died: waiting again before its send, unknown during it', function (): void {
    sendingCampaign(2);
    [$before, $during] = claimedJobs();
    CampaignMessage::query()->whereKey($during->messageId)->update(['status' => MessageStatus::Sending]);

    $before->failed(new RuntimeException('Timed out'));
    $during->failed(new RuntimeException('Timed out'));

    expect(CampaignMessage::query()->find($before->messageId))
        ->status->toBe(MessageStatus::Pending)
        ->claim_id->toBeNull()
        ->and(CampaignMessage::query()->find($during->messageId))
        ->status->toBe(MessageStatus::Unknown)
        ->failure_reason->toBe('unclear');
});

it('leaves a message unknown when a provider breaks its contract, and lets the error through', function (): void {
    sendingCampaign(1);
    [$job] = claimedJobs();
    app()->instance(CampaignProvider::class, new class extends FakeProvider
    {
        public function send(OutgoingMessage $message): SendReceipt
        {
            throw new RuntimeException('Broken provider');
        }
    });

    expect(fn () => runJob($job))->toThrow(RuntimeException::class, 'Broken provider')
        ->and(CampaignMessage::query()->sole())
        ->status->toBe(MessageStatus::Unknown)
        ->failure_reason->toBe('unclear');
});
