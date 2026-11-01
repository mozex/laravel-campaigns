<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ApplyFeedbackAction;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\Actions\UnsubscribeAction;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Support\Token;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
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
 * An event about an email in the package's terms, as any driver reports it:
 * each kind carries the time, code and message id of Mailgun's documented
 * sample of it, and nothing of Mailgun's own fields; an open or a click, the
 * package's own, comes now, as its pixel or link is hit. The Mailgun driver's
 * reading of its webhooks is tested apart.
 *
 * @param  array<string, mixed>  $fields  Feedback's arguments to replace
 */
function feedbackAbout(CampaignMessage|TestSend $email, string $kind, array $fields = []): Feedback
{
    $at = fn (float $timestamp): CarbonInterface => Carbon::createFromTimestampUTC($timestamp);

    $defaults = match ($kind) {
        'accepted' => ['type' => FeedbackType::Accepted, 'occurredAt' => $at(1770146798.372891), 'providerMessageId' => '20260203192638.000be97f99f8cea1@sample.mailgun.com'],
        'delivered' => ['type' => FeedbackType::Delivered, 'occurredAt' => $at(1770146431.6585283), 'code' => 250],
        'hard bounce' => ['type' => FeedbackType::Failed, 'occurredAt' => $at(1770918175.5923693), 'severity' => 'permanent', 'reason' => 'bounce', 'failure' => DeliveryFailure::HardBounce, 'code' => 550],
        'deferred' => ['type' => FeedbackType::Failed, 'occurredAt' => $at(1770919267.4288595), 'severity' => 'temporary', 'reason' => 'generic', 'failure' => DeliveryFailure::Deferred, 'code' => 421],
        'complained' => ['type' => FeedbackType::Complained, 'occurredAt' => $at(1770920772.2684145)],
        'unsubscribed' => ['type' => FeedbackType::Unsubscribed, 'occurredAt' => $at(1770327090.4656289)],
        'opened' => ['type' => FeedbackType::Opened, 'occurredAt' => now()],
        'clicked' => ['type' => FeedbackType::Clicked, 'occurredAt' => now(), 'url' => 'https://example.com/offer'],
    };

    return new Feedback(...[
        'providerEventId' => Str::random(22),
        ($email instanceof TestSend ? 'testSendUuid' : 'messageUuid') => $email->uuid,
        ...$defaults,
        ...$fields,
    ]);
}

/**
 * Records an event about an email as a driver's webhook would, and returns
 * it as stored, its effects not applied yet.
 *
 * @param  array<string, mixed>  $fields
 */
function reported(CampaignMessage|TestSend $email, string $kind, array $fields = []): ProviderEvent
{
    $feedback = feedbackAbout($email, $kind, $fields);

    expect(app(RecordFeedbackAction::class)->execute($feedback, 'fake'))->toBe(FeedbackResult::Stored);

    return ProviderEvent::query()->where('provider_event_id', $feedback->providerEventId)->sole();
}

/**
 * Runs the event's job, which applies its effects once.
 */
function process(ProviderEvent $event): void
{
    app()->call([new ProcessFeedbackJob($event->id), 'handle']);
}

/**
 * Reports an event and applies it at once.
 *
 * @param  array<string, mixed>  $fields
 */
function says(CampaignMessage|TestSend $email, string $kind, array $fields = []): ProviderEvent
{
    $event = reported($email, $kind, $fields);
    process($event);

    return $event;
}

function secondsAgo(int $seconds): CarbonInterface
{
    return now()->subSeconds($seconds);
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

        $delivered = says($message, 'delivered', ['occurredAt' => secondsAgo(1800)]);
        $afterDelivery = [$message->fresh()?->status, iso($message->fresh()?->delivered_at), $subscriber->fresh()?->soft_bounces];
        $bounce = says($message, 'hard bounce', ['occurredAt' => secondsAgo(600)]);
        // A delivery report read after the bounce leaves the message failed.
        says($message, 'delivered', ['occurredAt' => secondsAgo(1200)]);

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
        $gaveUp = ['severity' => 'permanent', 'reason' => 'old', 'failure' => DeliveryFailure::SoftBounce];
        $counts = [];
        $count = function () use ($subscriber, &$counts): void {
            $counts[] = $subscriber->fresh()?->soft_bounces;
        };

        // Still being tried, then given up on, reported twice.
        $first = messageTo($subscriber);
        says($first, 'deferred');
        $stillTried = $first->fresh()?->status;
        says($first, 'deferred', $gaveUp);
        says($first, 'deferred', $gaveUp);
        $count();

        // The receiving provider refused the sender, and the service's own
        // connection limit; then one tried while the sender was refused, and
        // given up on.
        $senderBlocked = messageTo($subscriber);
        says($senderBlocked, 'deferred', ['severity' => 'permanent', 'reason' => 'espblock', 'failure' => DeliveryFailure::SenderBlocked]);
        $ownLimit = messageTo($subscriber);
        says($ownLimit, 'hard bounce', ['reason' => 'generic', 'failure' => DeliveryFailure::SenderBlocked, 'code' => 611]);
        $refusedThenGaveUp = messageTo($subscriber);
        // An hour before the sample's own time, which the give-up keeps.
        says($refusedThenGaveUp, 'deferred', ['reason' => 'espblock', 'failure' => DeliveryFailure::SenderDeferred, 'occurredAt' => feedbackAbout($refusedThenGaveUp, 'deferred')->occurredAt->copy()->subHour()]);
        says($refusedThenGaveUp, 'deferred', $gaveUp);
        $count();

        // The servers couldn't be reached: still being tried, then given up on.
        $unreachable = messageTo($subscriber);
        says($unreachable, 'deferred', ['severity' => 'internal', 'code' => 612]);
        $count();
        says($unreachable, 'deferred', ['severity' => 'permanent', 'failure' => DeliveryFailure::SoftBounce, 'code' => 612]);
        $count();

        // A delivery between starts the count over.
        says(messageTo($subscriber), 'delivered');
        $count();

        says(messageTo($subscriber), 'deferred', $gaveUp);
        says(messageTo($subscriber), 'deferred', $gaveUp);
        $notYet = $subscriber->fresh()?->blocked_at;
        $third = messageTo($subscriber);
        $last = says($third, 'deferred', ['severity' => 'permanent', 'failure' => DeliveryFailure::SoftBounce]);

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
        $suppressed = fn (DeliveryFailure $failure): array => ['reason' => 'suppressed', 'failure' => $failure, 'code' => 605];
        [$bounced, $complained, $unsubscribed] = Subscriber::factory()->count(3)->create()
            ->map(fn (Subscriber $subscriber): CampaignMessage => messageTo($subscriber))
            ->all();

        says($bounced, 'hard bounce', $suppressed(DeliveryFailure::SuppressedBounce));
        says($complained, 'hard bounce', $suppressed(DeliveryFailure::SuppressedComplaint));
        says($unsubscribed, 'hard bounce', $suppressed(DeliveryFailure::SuppressedUnsubscribe));

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

        $complaint = says($message, 'complained', ['occurredAt' => secondsAgo(300)]);
        says($message, 'complained', ['occurredAt' => secondsAgo(100)]);

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

        says($message, 'unsubscribed');
        $unsubscribedAt = $message->fresh()?->unsubscribed_at;
        says($message, 'unsubscribed');

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

        says($complainedAbout, 'complained');
        $personAfterComplaint = $person->fresh();
        says($unsubscribedFrom, 'unsubscribed');
        says($bounced, 'hard bounce');

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

        $events = collect(['delivered', 'hard bounce', 'complained', 'unsubscribed', 'opened', 'clicked'])
            ->map(fn (string $kind): ProviderEvent => says($test, $kind));

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

        says($message, 'opened', ['occurredAt' => secondsAgo(600)]);
        // An earlier open read later: the email's first open moves back to
        // it, the person's latest stays.
        says($message, 'opened', ['occurredAt' => secondsAgo(900)]);
        $person = [iso($message->fresh()?->opened_at), iso($subscriber->fresh()?->last_opened_at)];
        says($message, 'opened', ['occurredAt' => secondsAgo(300), 'bot' => 'gmail']);
        $gmail = iso($subscriber->fresh()?->last_opened_at);
        says($message, 'opened', ['occurredAt' => secondsAgo(200), 'bot' => 'apple']);
        says($message, 'opened', ['occurredAt' => secondsAgo(100), 'bot' => 'generic']);
        // An open older than the person's own latest moves nothing back.
        says($other, 'opened', ['occurredAt' => secondsAgo(600)]);

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
        $deliveredAt = now()->subSeconds(3600)->getTimestamp();
        // Sent a minute before it was delivered: the scanner rule counts from
        // the delivery.
        $message = messageTo($subscriber, [
            'status' => MessageStatus::Delivered,
            'sent_at' => Carbon::createFromTimestampUTC($deliveredAt - 60),
            'delivered_at' => Carbon::createFromTimestampUTC($deliveredAt),
        ]);
        $click = fn (int $at, string $url): ProviderEvent => reported($message, 'clicked', [
            'occurredAt' => Carbon::createFromTimestampUTC($deliveredAt + $at),
            'url' => $url,
        ]);

        // Before the delivery landed: a gateway scanning the email on its way.
        $beforeArrival = $click(-30, 'https://example.com/offer');
        $onArrival = $click(5, 'https://example.com/offer');
        // A scanner opening every link at once: stored before either is applied.
        $burstFirst = $click(60, 'https://example.com/offer');
        $burstSecond = $click(61, 'https://example.com/pricing');
        $ownPage = $click(300, 'https://example.com/campaigns/u/'.Token::generate());
        $person = $click(400, 'https://example.com/offer');
        // The same link twice in a moment is a double click, not a scanner.
        $doubleClick = $click(401, 'https://example.com/offer');

        $clicks = collect([$beforeArrival, $onArrival, $burstFirst, $burstSecond, $ownPage, $person, $doubleClick]);
        $clicks->each(fn (ProviderEvent $event) => process($event));

        expect($clicks->map(fn (ProviderEvent $event): bool => (bool) $event->fresh()?->is_bot)->all())
            ->toBe([true, true, true, true, false, false, false])
            ->and($ownPage->fresh()?->payload['url'] ?? null)->toBe('https://example.com/campaigns/u/{token}')
            ->and(iso($message->fresh()?->clicked_at))->toBe(iso(Carbon::createFromTimestampUTC($deliveredAt + 400)))
            ->and(iso($subscriber->fresh()?->last_clicked_at))->toBe(iso(Carbon::createFromTimestampUTC($deliveredAt + 401)));
    })->with('databases');

    it('times a scanner\'s click from the send when no delivery was reported, as a driver that reads nothing back never reports one', function (): void {
        $message = messageTo(Subscriber::factory()->create(), ['sent_at' => now()->subHour()]);
        $click = fn (int $after): ProviderEvent => says($message, 'clicked', [
            'occurredAt' => now()->subHour()->addSeconds($after),
            'url' => 'https://example.com/offer',
        ]);

        $onArrival = $click(4);
        $person = $click(300);

        expect($onArrival->fresh()?->is_bot)->toBeTrue()
            ->and($person->fresh()?->is_bot)->toBeFalse()
            ->and(iso($message->fresh()?->clicked_at))->toBe(iso(now()->subHour()->addSeconds(300)));
    });

    it('applies an open and a click a minute later, so a scanner\'s burst and the delivery are stored by then, and other events at once', function (): void {
        $message = messageTo(Subscriber::factory()->create());

        $click = reported($message, 'clicked');
        $open = reported($message, 'opened');
        $delivered = reported($message, 'delivered');

        Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $click->id && $job->delay === ProcessFeedbackJob::ENGAGEMENT_DELAY);
        Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $open->id && $job->delay === ProcessFeedbackJob::ENGAGEMENT_DELAY);
        Queue::assertPushed(ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $delivered->id && $job->delay === null);
    });

    it('counts an open as the email arrived as a machine\'s, whatever its agent, Gmail\'s proxy included', function (string $driver): void {
        useDatabase($driver);
        $subscriber = Subscriber::factory()->create();
        $sent = messageTo($subscriber, ['sent_at' => secondsAgo(600)]);
        $delivered = messageTo(Subscriber::factory()->create(), [
            'status' => MessageStatus::Delivered,
            'sent_at' => secondsAgo(700),
            'delivered_at' => secondsAgo(600),
        ]);
        $open = fn (CampaignMessage $message, int $after, ?string $bot = null): ProviderEvent => says($message, 'opened', [
            'occurredAt' => secondsAgo(600 - $after),
            'bot' => $bot,
        ]);

        $events = [
            // Counted from the send, a driver that reads nothing back
            // reporting no delivery.
            $open($sent, 3, 'gmail'),
            $open($sent, 5),
            $open($sent, -2),
            $open($sent, 6, 'gmail'),
            // Counted from the delivery, a minute and more after the send.
            $open($delivered, 4),
            $open($delivered, 30),
        ];

        expect(array_map(fn (ProviderEvent $event): bool => (bool) $event->fresh()?->is_bot, $events))->toBe([true, true, true, false, true, false])
            ->and(iso($subscriber->fresh()?->last_opened_at))->toBe(iso(secondsAgo(594)))
            ->and(iso($sent->fresh()?->opened_at))->toBe(iso(secondsAgo(594)));
    })->with('databases');

    it('records nothing about a person who left from the mail they were sent', function (): void {
        // Kept for its opt-out, under the address the person last held,
        // which may not be the one this email went to.
        $kept = Subscriber::factory()->optedOut()->create();
        $message = messageTo($kept, ['email' => null]);
        $unclear = messageTo($kept, ['email' => null, 'status' => MessageStatus::Unknown, 'sent_at' => null]);

        says($unclear, 'accepted');
        says($message, 'opened');
        says($message, 'clicked');
        says($message, 'complained');
        says($message, 'hard bounce');

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
    it('dates an unclear send from when its job sent it, so a person\'s open and click right after count', function (): void {
        $message = messageTo(Subscriber::factory()->create(), [
            'status' => MessageStatus::Unknown,
            'sending_at' => secondsAgo(600),
            'sent_at' => null,
        ]);

        $open = says($message, 'opened', ['occurredAt' => secondsAgo(60)]);
        $click = says($message, 'clicked', ['occurredAt' => secondsAgo(54)]);

        expect($message->fresh())
            ->status->toBe(MessageStatus::Sent)
            ->and(iso($message->fresh()?->sent_at))->toBe(iso(secondsAgo(600)))
            ->and(iso($message->subscriber?->fresh()?->first_mailed_at))->toBe(iso(secondsAgo(600)))
            ->and($open->fresh()?->is_bot)->toBeFalse()
            ->and($click->fresh()?->is_bot)->toBeFalse();
    });

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

        $messages->each(fn (CampaignMessage $message) => says($message, 'accepted', ['occurredAt' => secondsAgo(60)]));

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

        says($skipped, 'delivered');
        says($failed, 'delivered');
        says($failed, 'deferred', ['severity' => 'permanent', 'reason' => 'old', 'failure' => DeliveryFailure::SoftBounce]);

        expect($skipped->fresh())->status->toBe(MessageStatus::Skipped)->sent_at->toBeNull()
            ->and($failed->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('hard_bounce')
            // Its delivery read after its failure neither counts nor clears.
            ->and($failed->subscriber?->fresh()?->soft_bounces)->toBe(1);
    });
});

it('tells what changed for the campaign: a failure now, a first complaint, an unsubscribe that counted', function (): void {
    $message = messageTo(Subscriber::factory()->create());
    $apply = fn (ProviderEvent $event): array => (array) app(ApplyFeedbackAction::class)->execute($event);

    $temporary = $apply(reported($message, 'deferred'));
    $bounce = $apply(reported($message, 'hard bounce'));
    $again = $apply(reported($message, 'hard bounce'));
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

it('runs the stop-loss once the effects have committed, a pause listener that fails leaving the event processed', function (): void {
    config()->set('campaigns.enabled', true);
    Exceptions::fake();
    Event::listen(CampaignPausedEvent::class, function (): void {
        throw new RuntimeException('the alert broke');
    });

    $campaign = Campaign::factory()->sending()->create();
    sends($campaign, 90);
    sends($campaign, 9, hardBounce());
    $message = messageTo(Subscriber::factory()->create(), ['campaign_id' => $campaign->id]);

    $event = says($message, 'hard bounce');

    expect($message->fresh()?->failure_reason)->toBe('hard_bounce')
        ->and($event->fresh()?->processed_at)->not->toBeNull()
        ->and($campaign->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toBe('The stop-loss paused it: 10 of 100 emails bounced hard, reaching the limit of 10 before 200 emails (early_hard_bounces).');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the alert broke');
});
