<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ApplyFeedbackAction;
use Mozex\Campaigns\Actions\UnsubscribeAction;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Support\Token;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
    config()->set('campaigns.mailgun.webhook_signing_key', 'webhook-signing-key');
    Queue::fake([ProcessFeedbackJob::class]);
});

/**
 * A campaign message the provider took, to the subscriber's address.
 *
 * @param  array<string, mixed>  $attributes
 */
function messageTo(Subscriber $subscriber, array $attributes = []): CampaignMessage
{
    return CampaignMessage::factory()->for($subscriber)->create([
        'status' => MessageStatus::Sent,
        'sent_at' => now()->subHour(),
        ...$attributes,
    ]);
}

/**
 * Posts Mailgun's documented event about an email as a signed webhook, with
 * the given fields replaced and an id of its own, and returns it as stored,
 * its effects not applied yet.
 *
 * @param  array<string, mixed>  $changes
 */
function reported(CampaignMessage|TestSend $email, string $fixture, array $changes = []): ProviderEvent
{
    $key = $email instanceof TestSend ? 'campaign-test' : 'campaign-message';
    $event = mailgunEvent($fixture, ['id' => Str::random(22), 'user-variables' => [$key => $email->uuid], ...$changes]);

    test()->postJson(route('campaigns.webhooks.mailgun'), mailgunWebhook($event))->assertOk();

    return ProviderEvent::query()->where('provider_event_id', $event['id'])->sole();
}

/**
 * Runs the event's job, which applies its effects once.
 */
function process(ProviderEvent $event): void
{
    (new ProcessFeedbackJob($event->id))->handle(app(ApplyFeedbackAction::class));
}

/**
 * Reports an event and applies it at once.
 *
 * @param  array<string, mixed>  $changes
 */
function mailgunSays(CampaignMessage|TestSend $email, string $fixture, array $changes = []): ProviderEvent
{
    $event = reported($email, $fixture, $changes);
    process($event);

    return $event;
}

/**
 * A Mailgun timestamp this many seconds before the test's now.
 */
function secondsAgo(int $seconds): int
{
    return now()->subSeconds($seconds)->getTimestamp();
}

function iso(?CarbonInterface $date): ?string
{
    return $date?->toIso8601String();
}

describe('deliveries and failures', function (): void {
    it('delivers a sent message, starts its soft bounces over, and fails it on a late bounce', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create(['soft_bounces' => 2]);
        $message = messageTo($subscriber);

        $delivered = mailgunSays($message, 'delivered', ['timestamp' => secondsAgo(1800)]);
        $afterDelivery = [$message->fresh()?->status, iso($message->fresh()?->delivered_at), $subscriber->fresh()?->soft_bounces];
        $bounce = mailgunSays($message, 'permanent-failure', ['timestamp' => secondsAgo(600)]);
        // A delivery report read after the bounce leaves the message failed.
        mailgunSays($message, 'delivered', ['timestamp' => secondsAgo(1200)]);

        $block = ConsentRecord::query()->where('action', ConsentAction::Block)->sole();

        expect($afterDelivery)->toBe([MessageStatus::Delivered, iso(now()->subSeconds(1800)), 0])
            ->and($message->fresh())
            ->status->toBe(MessageStatus::Failed)
            ->failure_reason->toBe('hard_bounce')
            ->failure_severity->toBe('permanent')
            ->failure_code->toBe(550)
            ->and(iso($message->fresh()?->failed_at))->toBe(iso(now()->subSeconds(600)))
            ->and(iso($message->fresh()?->delivered_at))->toBe(iso(now()->subSeconds(1800)))
            ->and($subscriber->fresh())
            ->blocked_reason->toBe(BlockReason::HardBounce)
            ->opted_out_at->toBeNull()
            ->and($block->subscriber_id)->toBe($subscriber->id)
            ->and($block->source)->toBe(ApplyFeedbackAction::BOUNCE)
            ->and($block->meta)->toEqual(['event' => $bounce->provider_event_id, 'message' => $message->uuid, 'reason' => 'hard_bounce'])
            ->and($delivered->fresh()?->processed_at)->not->toBeNull();
    })->with('databases');

    it('counts a soft bounce once per message, never a sender block or code 611, and blocks the third in a row', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create();
        $gaveUp = ['severity' => 'permanent', 'reason' => 'old'];
        $counts = [];
        $count = function () use ($subscriber, &$counts): void {
            $counts[] = $subscriber->fresh()?->soft_bounces;
        };

        // Still being tried, then given up on, reported twice.
        $first = messageTo($subscriber);
        mailgunSays($first, 'temporary-failure');
        $stillTried = $first->fresh()?->status;
        mailgunSays($first, 'temporary-failure', $gaveUp);
        mailgunSays($first, 'temporary-failure', $gaveUp);
        $count();

        // The receiving provider refused the sender, and Mailgun's own limit
        // (its bounce type hard, which the code outranks); then one tried
        // while the sender was refused, and given up on.
        $senderBlocked = messageTo($subscriber);
        mailgunSays($senderBlocked, 'temporary-failure', ['severity' => 'permanent', 'reason' => 'espblock']);
        $ownLimit = messageTo($subscriber);
        mailgunSays($ownLimit, 'permanent-failure', ['reason' => 'generic', 'delivery-status' => ['code' => 611]]);
        $refusedThenGaveUp = messageTo($subscriber);
        // An hour before the sample's own time, which the give-up keeps.
        mailgunSays($refusedThenGaveUp, 'temporary-failure', ['reason' => 'espblock', 'timestamp' => mailgunEvent('temporary-failure')['timestamp'] - 3600]);
        mailgunSays($refusedThenGaveUp, 'temporary-failure', $gaveUp);
        $count();

        // Mailgun couldn't reach the servers: still trying, then gave up.
        $unreachable = messageTo($subscriber);
        mailgunSays($unreachable, 'temporary-failure', ['severity' => 'internal', 'delivery-status' => ['code' => 612]]);
        $count();
        mailgunSays($unreachable, 'temporary-failure', ['severity' => 'permanent', 'delivery-status' => ['code' => 612]]);
        $count();

        // A delivery between starts the count over.
        mailgunSays(messageTo($subscriber), 'delivered');
        $count();

        mailgunSays(messageTo($subscriber), 'temporary-failure', $gaveUp);
        mailgunSays(messageTo($subscriber), 'temporary-failure', $gaveUp);
        $notYet = $subscriber->fresh()?->blocked_at;
        $third = messageTo($subscriber);
        $last = mailgunSays($third, 'temporary-failure', ['severity' => 'permanent', 'delivery-status' => ['bounce-type' => 'soft']]);

        $block = ConsentRecord::query()->where('action', ConsentAction::Block)->sole();

        expect($stillTried)->toBe(MessageStatus::Sent)
            ->and($counts)->toBe([1, 1, 1, 2, 0])
            ->and($notYet)->toBeNull()
            ->and($subscriber->fresh())
            ->soft_bounces->toBe(3)
            ->blocked_reason->toBe(BlockReason::SoftBounces)
            ->and($block->source)->toBe(ApplyFeedbackAction::BOUNCE)
            ->and($block->meta)->toEqual(['event' => $last->provider_event_id, 'message' => $third->uuid, 'soft_bounces' => 3, 'reason' => 'soft_bounces'])
            ->and($first->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('soft_bounce')
            ->and($senderBlocked->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('sender_blocked')
            ->and($ownLimit->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('sender_blocked')->failure_code->toBe(611)
            ->and($refusedThenGaveUp->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('sender_blocked')
            ->and($unreachable->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('soft_bounce');
    })->with('databases');

    it('blocks an address on the provider\'s bounce and complaint lists, and opts out one on its unsubscribe list', function (string $driver): void {
        useDatabase($driver);
        $suppressed = fn (string $reason): array => ['reason' => $reason, 'delivery-status' => ['code' => 605, 'bounce-type' => null]];
        [$bounced, $complained, $unsubscribed] = Subscriber::factory()->count(3)->create()
            ->map(fn (Subscriber $subscriber): CampaignMessage => messageTo($subscriber))
            ->all();

        mailgunSays($bounced, 'permanent-failure', $suppressed('suppress-bounce'));
        mailgunSays($complained, 'permanent-failure', $suppressed('suppress-complaint'));
        mailgunSays($unsubscribed, 'permanent-failure', $suppressed('suppress-unsubscribe'));

        expect($bounced->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('suppressed_bounce')
            ->and($bounced->subscriber?->fresh())->blocked_reason->toBe(BlockReason::HardBounce)->opted_out_at->toBeNull()
            ->and($complained->fresh()?->failure_reason)->toBe('suppressed_complaint')
            ->and($complained->subscriber?->fresh())->blocked_reason->toBe(BlockReason::Complaint)->opted_out_at->toBeNull()
            ->and($unsubscribed->fresh())->failure_reason->toBe('suppressed_unsubscribe')->unsubscribed_at->toBeNull()
            ->and($unsubscribed->subscriber?->fresh())->opted_out_at->not->toBeNull()->blocked_at->toBeNull()
            ->and(ConsentRecord::query()->pluck('source')->unique()->all())->toBe([ApplyFeedbackAction::SUPPRESSION]);
    })->with('databases');
});

describe('spam reports and unsubscribes', function (): void {
    it('blocks an address that reports spam and opts the person out of everything, once', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create();
        $message = messageTo($subscriber);

        $complaint = mailgunSays($message, 'complained', ['timestamp' => secondsAgo(300)]);
        mailgunSays($message, 'complained', ['timestamp' => secondsAgo(100)]);

        expect(iso($message->fresh()?->complained_at))->toBe(iso(now()->subSeconds(300)))
            ->and($message->fresh()?->status)->toBe(MessageStatus::Sent)
            ->and($subscriber->fresh())
            ->blocked_reason->toBe(BlockReason::Complaint)
            ->opted_out_at->not->toBeNull()
            ->and(consentActions($subscriber))->toBe(['block', 'opt_out'])
            ->and(ConsentRecord::query()->get()->map(fn (ConsentRecord $record): array => [$record->source, $record->meta['event'] ?? null])->all())
            ->toBe([
                [ApplyFeedbackAction::COMPLAINT, $complaint->provider_event_id],
                [ApplyFeedbackAction::COMPLAINT, $complaint->provider_event_id],
            ]);
    })->with('databases');

    it('opts the person out through the provider\'s own unsubscribe, counted against the email', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create();
        $message = messageTo($subscriber);

        mailgunSays($message, 'unsubscribed');
        $unsubscribedAt = $message->fresh()?->unsubscribed_at;
        mailgunSays($message, 'unsubscribed');

        $record = ConsentRecord::query()->sole();

        expect($unsubscribedAt)->not->toBeNull()
            ->and($message->fresh()?->unsubscribed_at?->equalTo($unsubscribedAt))->toBeTrue()
            ->and($subscriber->fresh())->opted_out_at->not->toBeNull()->blocked_at->toBeNull()
            ->and($record->action)->toBe(ConsentAction::OptOut)
            ->and($record->source)->toBe(UnsubscribeAction::PROVIDER)
            ->and($record->meta['message'] ?? null)->toBe($message->uuid);
    })->with('databases');

    it('acts on the old address for mail an address change moved, never on the person', function (string $driver): void {
        useDatabase($driver);
        $person = Subscriber::factory()->create(['email' => 'reader-new@example.org']);
        $complainedAbout = messageTo($person, ['email' => 'reader-old@example.org']);
        $unsubscribedFrom = messageTo($person, ['email' => 'reader-old@example.org']);
        // Someone else who moved, whose old address kept its row (opted out).
        $mover = Subscriber::factory()->create(['email' => 'mover-new@example.org']);
        $kept = Subscriber::factory()->optedOut()->create(['email' => 'mover-old@example.org']);
        $bounced = messageTo($mover, ['email' => 'mover-old@example.org']);

        mailgunSays($complainedAbout, 'complained');
        $personAfterComplaint = $person->fresh();
        mailgunSays($unsubscribedFrom, 'unsubscribed');
        mailgunSays($bounced, 'permanent-failure');

        $oldAddress = Subscriber::findByEmail('reader-old@example.org');

        expect($personAfterComplaint)->blocked_at->toBeNull()->opted_out_at->toBeNull()
            ->and($complainedAbout->fresh()?->complained_at)->not->toBeNull()
            // A row holding only the old address, blocked.
            ->and($oldAddress)
            ->blocked_reason->toBe(BlockReason::Complaint)
            ->opted_out_at->toBeNull()
            ->subscribable_type->toBeNull()
            ->name->toBeNull()
            ->and($oldAddress?->subscriptions()->count())->toBe(0)
            // The old email's unsubscribe still reaches the person.
            ->and($person->fresh())->opted_out_at->not->toBeNull()->blocked_at->toBeNull()
            ->and($kept->fresh()?->blocked_reason)->toBe(BlockReason::HardBounce)
            ->and($mover->fresh()?->blocked_at)->toBeNull();
    })->with('databases');

    it('changes nothing for a test email\'s events but keeping them', function (string $driver): void {
        useDatabase($driver);
        $address = Subscriber::factory()->create();
        $test = TestSend::factory()->create(['email' => $address->email, 'provider_message_id' => 'test@example.org']);

        $events = collect(['delivered', 'permanent-failure', 'complained', 'unsubscribed', 'opened', 'clicked'])
            ->map(fn (string $fixture): ProviderEvent => mailgunSays($test, $fixture));

        $applied = app(ApplyFeedbackAction::class)->execute($events->first());

        expect($events->every(fn (ProviderEvent $event): bool => $event->fresh()?->test_send_id === $test->id && $event->fresh()->processed_at !== null))->toBeTrue()
            ->and($address->fresh())->blocked_at->toBeNull()->opted_out_at->toBeNull()->last_opened_at->toBeNull()->last_clicked_at->toBeNull()
            ->and($test->fresh())->unsubscribed_at->toBeNull()->failed_at->toBeNull()
            ->and(ConsentRecord::query()->count())->toBe(0)
            ->and($applied->campaignId)->toBeNull();
    })->with('databases');
});

describe('opens and clicks', function (): void {
    it('counts a person\'s opens, Gmail\'s proxy among them, and never a machine\'s', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create(['last_opened_at' => now()->subDays(30)]);
        $message = messageTo($subscriber);
        $other = messageTo(Subscriber::factory()->create(['last_opened_at' => now()]));

        mailgunSays($message, 'opened', ['timestamp' => secondsAgo(600)]);
        // An earlier open read later: the email's first open moves back to
        // it, the person's latest stays.
        mailgunSays($message, 'opened', ['timestamp' => secondsAgo(900)]);
        $person = [iso($message->fresh()?->opened_at), iso($subscriber->fresh()?->last_opened_at)];
        mailgunSays($message, 'opened', ['timestamp' => secondsAgo(300), 'client-info' => ['bot' => 'gmail']]);
        $gmail = iso($subscriber->fresh()?->last_opened_at);
        mailgunSays($message, 'opened', ['timestamp' => secondsAgo(200), 'client-info' => ['bot' => 'apple']]);
        mailgunSays($message, 'opened', ['timestamp' => secondsAgo(100), 'client-info' => ['bot' => 'generic']]);
        // An open older than the person's own latest moves nothing back.
        mailgunSays($other, 'opened', ['timestamp' => secondsAgo(600)]);

        expect($person)->toBe([iso(now()->subSeconds(900)), iso(now()->subSeconds(600))])
            ->and($gmail)->toBe(iso(now()->subSeconds(300)))
            ->and(iso($subscriber->fresh()?->last_opened_at))->toBe(iso(now()->subSeconds(300)))
            ->and(iso($message->fresh()?->opened_at))->toBe(iso(now()->subSeconds(900)))
            ->and(iso($other->subscriber?->fresh()?->last_opened_at))->toBe(iso(now()))
            ->and(iso($other->fresh()?->opened_at))->toBe(iso(now()->subSeconds(600)));
    })->with('databases');

    it('counts a person\'s click, never a scanner\'s or one on the package\'s own pages', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create();
        $message = messageTo($subscriber);
        $deliveredAt = secondsAgo(3600);
        $click = fn (int $at, string $url): ProviderEvent => reported($message, 'clicked', [
            'timestamp' => $deliveredAt + $at,
            'delivered-at' => $deliveredAt,
            'url' => $url,
        ]);

        $onArrival = $click(5, 'https://example.com/offer');
        // A scanner opening every link at once: stored before either is applied.
        $burstFirst = $click(60, 'https://example.com/offer');
        $burstSecond = $click(61, 'https://example.com/pricing');
        $ownPage = $click(300, 'https://example.com/campaigns/u/'.Token::generate());
        $person = $click(400, 'https://example.com/offer');
        // The same link twice in a moment is a double click, not a scanner.
        $doubleClick = $click(401, 'https://example.com/offer');

        $clicks = collect([$onArrival, $burstFirst, $burstSecond, $ownPage, $person, $doubleClick]);
        $clicks->each(fn (ProviderEvent $event) => process($event));

        expect($clicks->map(fn (ProviderEvent $event): bool => (bool) $event->fresh()?->is_bot)->all())
            ->toBe([true, true, true, false, false, false])
            ->and($ownPage->fresh()?->payload['url'] ?? null)->toBe('https://example.com/campaigns/u/{token}')
            ->and(iso($message->fresh()?->clicked_at))->toBe(iso(Carbon::createFromTimestampUTC($deliveredAt + 400)))
            ->and(iso($subscriber->fresh()?->last_clicked_at))->toBe(iso(Carbon::createFromTimestampUTC($deliveredAt + 401)));
    })->with('databases');

    it('applies a click a minute later, so a scanner\'s burst is stored by then, and an open at once', function (): void {
        $message = messageTo(Subscriber::factory()->create());

        $click = reported($message, 'clicked');
        $open = reported($message, 'opened');

        Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $click->id && $job->delay === ProcessFeedbackJob::CLICK_DELAY);
        Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $open->id && $job->delay === null);
    });

    it('records nothing about a person who left from the mail they were sent', function (): void {
        // Kept for its opt-out, under the address the person last held,
        // which may not be the one this email went to.
        $kept = Subscriber::factory()->optedOut()->create();
        $message = messageTo($kept, ['email' => null]);
        $unclear = messageTo($kept, ['email' => null, 'status' => MessageStatus::Unknown, 'sent_at' => null]);

        mailgunSays($unclear, 'accepted');
        mailgunSays($message, 'opened');
        mailgunSays($message, 'clicked', ['delivered-at' => null]);
        mailgunSays($message, 'complained');
        mailgunSays($message, 'permanent-failure');

        expect($kept->fresh())
            ->last_opened_at->toBeNull()
            ->last_clicked_at->toBeNull()
            ->blocked_at->toBeNull()
            ->first_mailed_at->toBeNull()
            ->and($unclear->fresh()?->status)->toBe(MessageStatus::Sent)
            ->and($message->fresh())
            ->opened_at->not->toBeNull()
            ->clicked_at->not->toBeNull()
            ->complained_at->not->toBeNull()
            ->status->toBe(MessageStatus::Failed)
            ->and(ConsentRecord::query()->count())->toBe(0);
    });
});

describe('what proves a message went out', function (): void {
    it('marks sent a message its send job never did, so it never goes again, and the address mailed', function (string $driver): void {
        useDatabase($driver);
        // An unclear answer, one put back as "taken nothing", and one claimed
        // again after that.
        $messages = collect([MessageStatus::Unknown, MessageStatus::Pending, MessageStatus::Queued])
            ->map(fn (MessageStatus $status): CampaignMessage => messageTo(Subscriber::factory()->create(), [
                'status' => $status,
                'sent_at' => null,
                'failure_reason' => 'unclear',
                'failure_code' => 504,
            ]));

        $messages->each(fn (CampaignMessage $message) => mailgunSays($message, 'accepted', ['timestamp' => secondsAgo(60)]));

        expect($messages->map(fn (CampaignMessage $message): array => [
            $message->fresh()?->status,
            $message->fresh()?->failure_reason,
            $message->fresh()?->failure_code,
            $message->fresh()?->provider_message_id,
            iso($message->fresh()?->sent_at),
            iso($message->subscriber?->fresh()?->first_mailed_at),
        ])->unique()->values()->all())->toBe([[
            MessageStatus::Sent,
            null,
            null,
            '20260203192638.000be97f99f8cea1@sample.mailgun.com',
            iso(now()->subSeconds(60)),
            iso(now()->subSeconds(60)),
        ]]);
    })->with('databases');

    it('leaves a message never sent, and one already failed, where they are', function (): void {
        $skipped = messageTo(Subscriber::factory()->create(), ['status' => MessageStatus::Skipped, 'sent_at' => null]);
        $failed = messageTo(Subscriber::factory()->create(['soft_bounces' => 1]), ['status' => MessageStatus::Failed, 'failure_reason' => 'hard_bounce']);

        mailgunSays($skipped, 'delivered');
        mailgunSays($failed, 'delivered');
        mailgunSays($failed, 'temporary-failure', ['severity' => 'permanent', 'reason' => 'old']);

        expect($skipped->fresh())->status->toBe(MessageStatus::Skipped)->sent_at->toBeNull()
            ->and($failed->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('hard_bounce')
            // Its delivery read after its failure neither counts nor clears.
            ->and($failed->subscriber?->fresh()?->soft_bounces)->toBe(1);
    });
});

it('tells what changed for the campaign: a failure now, a first complaint, an unsubscribe that counted', function (): void {
    $message = messageTo(Subscriber::factory()->create());
    $apply = fn (ProviderEvent $event): array => (array) app(ApplyFeedbackAction::class)->execute($event);

    $temporary = $apply(reported($message, 'temporary-failure'));
    $bounce = $apply(reported($message, 'permanent-failure'));
    $again = $apply(reported($message, 'permanent-failure'));
    $complaint = $apply(reported($message, 'complained'));
    $secondComplaint = $apply(reported($message, 'complained'));

    $other = messageTo(Subscriber::factory()->create());
    $unsubscribe = $apply(reported($other, 'unsubscribed'));
    $secondUnsubscribe = $apply(reported($other, 'unsubscribed'));

    $nothing = ['campaignId' => $message->campaign_id, 'failed' => null, 'complained' => false, 'unsubscribed' => false];

    expect($temporary)->toBe($nothing)
        ->and($bounce)->toBe([...$nothing, 'failed' => DeliveryFailure::HardBounce])
        ->and($again)->toBe($nothing)
        ->and($complaint)->toBe([...$nothing, 'complained' => true])
        ->and($secondComplaint)->toBe($nothing)
        ->and($unsubscribe)->toBe([...$nothing, 'campaignId' => $other->campaign_id, 'unsubscribed' => true])
        ->and($secondUnsubscribe)->toBe([...$nothing, 'campaignId' => $other->campaign_id]);
});
