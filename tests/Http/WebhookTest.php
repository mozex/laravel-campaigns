<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mozex\Campaigns\Actions\RecordFeedbackAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\FeedbackResult;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Jobs\ProcessFeedbackJob;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Models\WebhookToken;
use Mozex\Campaigns\Sending\Feedback;
use Mozex\Campaigns\Support\Token;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');
    Queue::fake([ProcessFeedbackJob::class]);
});

/**
 * Mailgun's documented event about the given campaign message.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function eventFor(CampaignMessage $message, string $name = 'delivered', array $changes = []): array
{
    return mailgunEvent($name, ['user-variables' => ['campaign-message' => $message->uuid], ...$changes]);
}

/**
 * Posts a webhook body and returns the status it was answered with.
 *
 * @param  array<string, mixed>  $body
 */
function postWebhook(array $body): int
{
    return test()->postJson(route('campaigns.webhooks', 'mailgun'), $body)->status();
}

describe('verifying', function (): void {
    it('accepts a request signed with the signing key and refuses every other', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();
        $now = now()->getTimestamp();

        $signed = mailgunWebhook(eventFor($message));
        $wrongSignature = mailgunWebhook(eventFor($message, changes: ['id' => 'event-two']));
        $wrongSignature['signature']['signature'] = str_repeat('0', 64);
        $parent = mailgunWebhook(eventFor($message, changes: ['id' => 'event-three']), key: 'another-key');
        $parent['signature']['parent-signature'] = hash_hmac('sha256', $parent['signature']['timestamp'].$parent['signature']['token'], 'webhook-signing-key');
        $noToken = mailgunWebhook(eventFor($message, changes: ['id' => 'event-four']));
        unset($noToken['signature']['token']);

        expect(postWebhook($signed))->toBe(200)
            ->and(postWebhook($wrongSignature))->toBe(406)
            ->and(postWebhook(mailgunWebhook(eventFor($message, changes: ['id' => 'event-five']), key: 'another-key')))->toBe(406)
            ->and(postWebhook($parent))->toBe(200)
            ->and(postWebhook($noToken))->toBe(406)
            ->and(postWebhook(['event-data' => eventFor($message, changes: ['id' => 'event-six'])]))->toBe(406)
            // Mailgun retries for about 8 hours, so a day either way passes,
            // and anything older or further ahead doesn't.
            ->and(postWebhook(mailgunWebhook(eventFor($message, changes: ['id' => 'event-seven']), timestamp: $now - 86400)))->toBe(200)
            ->and(postWebhook(mailgunWebhook(eventFor($message, changes: ['id' => 'event-eight']), timestamp: $now - 86401)))->toBe(406)
            ->and(postWebhook(mailgunWebhook(eventFor($message, changes: ['id' => 'event-nine']), timestamp: $now + 86401)))->toBe(406)
            ->and(ProviderEvent::query()->orderBy('id')->pluck('provider_event_id')->all())
            ->toBe(['MXcc2gEpS-eN8HfkOnmK2w', 'event-three', 'event-seven']);

        // A form post, as a route forward would send, isn't a webhook.
        $this->post(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(eventFor($message, changes: ['id' => 'event-ten'])))
            ->assertStatus(406);

        expect(ProviderEvent::query()->count())->toBe(3)
            ->and(WebhookToken::query()->count())->toBe(3);
    })->with('databases');

    it('answers 500 while the active driver has no signing key, so Mailgun retries, and reports it once an hour', function (): void {
        Exceptions::fake();
        config()->set('campaigns.driver', 'mailgun');
        config()->set('campaigns.drivers.mailgun.webhook_signing_key', null);
        $message = CampaignMessage::factory()->create();

        $this->postJson(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(eventFor($message)))->assertStatus(500);
        $this->postJson(route('campaigns.webhooks', 'mailgun'), [])->assertStatus(500);

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(CampaignsException::class);

        $this->travel(61)->minutes();
        $this->postJson(route('campaigns.webhooks', 'mailgun'), [])->assertStatus(500);

        Exceptions::assertReportedCount(2);

        expect(ProviderEvent::query()->count())->toBe(0);
    });

    it('runs outside the app\'s web middleware and any throttle, at Mailgun\'s same address', function (): void {
        $route = Route::getRoutes()->getByName('campaigns.webhooks');

        expect($route?->methods())->toBe(['POST'])
            ->and($route?->uri())->toBe('campaigns/webhooks/{driver}')
            ->and(route('campaigns.webhooks', 'mailgun', absolute: false))->toBe('/campaigns/webhooks/mailgun')
            ->and($route?->gatherMiddleware())->not->toContain('web')
            ->and(collect($route?->gatherMiddleware())->filter(fn (string $middleware): bool => str_starts_with($middleware, 'throttle')))->toBeEmpty();
    });
});

describe('storing', function (): void {
    it('stores an event once with its token, and refuses a token with another event', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();
        $body = mailgunWebhook(eventFor($message), token: 'token-one');

        expect(postWebhook($body))->toBe(200);

        $event = ProviderEvent::query()->sole();

        expect($event->message_id)->toBe($message->id)
            ->and($event->test_send_id)->toBeNull()
            ->and($event->type)->toBe(FeedbackType::Delivered)
            ->and($event->occurred_at->toIso8601String())->toBe(Carbon::createFromTimestampUTC(1770146431)->toIso8601String())
            ->and($event->processed_at)->toBeNull()
            ->and(WebhookToken::query()->sole()->only(['token_hash', 'provider_event_id']))
            ->toBe(['token_hash' => Token::hash('token-one'), 'provider_event_id' => 'MXcc2gEpS-eN8HfkOnmK2w']);

        Queue::assertPushedOn('campaigns', ProcessFeedbackJob::class, fn (ProcessFeedbackJob $job): bool => $job->eventId === $event->id);

        // The answer was lost and Mailgun posts the same request again; it
        // signs a retry anew; then someone replays either token with another
        // event, or the first token with the same event's id and other
        // contents.
        $forged = mailgunWebhook(eventFor($message, 'complained'), token: 'token-one');
        $forgedSameId = $forged;
        $forgedSameId['event-data']['id'] = 'MXcc2gEpS-eN8HfkOnmK2w';

        expect(postWebhook($body))->toBe(200)
            ->and(postWebhook(mailgunWebhook(eventFor($message), token: 'token-two')))->toBe(200)
            ->and(WebhookToken::query()->orderBy('id')->pluck('provider_event_id')->all())->toBe(['MXcc2gEpS-eN8HfkOnmK2w', 'MXcc2gEpS-eN8HfkOnmK2w'])
            ->and(postWebhook($forged))->toBe(406)
            ->and(postWebhook(mailgunWebhook(eventFor($message, 'complained'), token: 'token-two')))->toBe(406)
            ->and(postWebhook($forgedSameId))->toBe(200)
            ->and(ProviderEvent::query()->count())->toBe(1)
            ->and(ProviderEvent::query()->sole()->type)->toBe(FeedbackType::Delivered);

        // The same event read back from the provider's logs carries no token,
        // and a caller's transaction stays usable after the lost insert
        // (PostgreSQL aborts a transaction on any failed statement).
        $fromLogs = new Feedback(providerEventId: 'MXcc2gEpS-eN8HfkOnmK2w', type: FeedbackType::Delivered, occurredAt: now(), messageUuid: $message->uuid);

        $result = DB::transaction(fn (): array => [
            app(RecordFeedbackAction::class)->execute($fromLogs, 'mailgun'),
            ProviderEvent::query()->count(),
        ]);

        // Another driver's event of the same id is its own.
        $otherDriver = app(RecordFeedbackAction::class)->execute($fromLogs, 'fake');

        expect($result)->toBe([FeedbackResult::Duplicate, 1])
            ->and($otherDriver)->toBe(FeedbackResult::Stored)
            ->and(ProviderEvent::query()->orderBy('id')->pluck('driver')->all())->toBe(['mailgun', 'fake']);
    })->with('databases');

    it('queues an event again when it comes back unprocessed, and only then', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();

        expect(postWebhook(mailgunWebhook(eventFor($message))))->toBe(200);

        Queue::assertPushed(ProcessFeedbackJob::class, 1);

        // The queue was down, or every try failed: the event's retry, signed
        // anew, queues it again.
        expect(postWebhook(mailgunWebhook(eventFor($message))))->toBe(200);

        Queue::assertPushed(ProcessFeedbackJob::class, 2);

        app()->call([new ProcessFeedbackJob(ProviderEvent::query()->sole()->id), 'handle']);

        expect(postWebhook(mailgunWebhook(eventFor($message))))->toBe(200);

        Queue::assertPushed(ProcessFeedbackJob::class, 2);
    })->with('databases');

    it('accepts a retry of a request whose event failed to store', function (string $driver, Closure $failure): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();
        $body = mailgunWebhook(eventFor($message), token: 'token-one');
        $failing = true;

        ProviderEvent::creating(function () use (&$failing, $failure): void {
            if ($failing) {
                $failing = false;

                throw $failure();
            }
        });

        $this->postJson(route('campaigns.webhooks', 'mailgun'), $body)->assertStatus(500);

        expect(ProviderEvent::query()->count())->toBe(0)
            ->and(WebhookToken::query()->count())->toBe(0);

        $this->postJson(route('campaigns.webhooks', 'mailgun'), $body)->assertOk();

        expect(ProviderEvent::query()->count())->toBe(1)
            ->and(WebhookToken::query()->sole()->token_hash)->toBe(Token::hash('token-one'));
    })->with('databases')->with([
        // Pest hands a closure to a parameter typed Closure as it is.
        'the database went away' => fn (): Throwable => new RuntimeException('The database went away.'),
        // Not the event's own id: a primary key behind its sequence after a
        // restore, which must not read as "stored already".
        'another unique clash' => fn (): Throwable => new UniqueConstraintViolationException('testing', 'insert into campaign_provider_events', [], new PDOException('duplicate key value violates unique constraint "campaign_provider_events_pkey"')),
    ]);

    it('finds the email by its uuid, else by the provider\'s message id among what the driver sent, and a test email by its own uuid', function (string $driver): void {
        useDatabase($driver);
        $byUuid = CampaignMessage::factory()->create();
        // Another service's message under the same id, which the fallback never reaches.
        CampaignMessage::factory()->create(['driver' => 'fake', 'provider_message_id' => '20260212174255.58bbb7ce85a423e5@mailgun.com']);
        $byProviderId = CampaignMessage::factory()->create(['driver' => 'mailgun', 'provider_message_id' => '20260212174255.58bbb7ce85a423e5@mailgun.com']);
        $testSend = TestSend::factory()->create();
        TestSend::factory()->create(['driver' => 'fake', 'provider_message_id' => '20260205213049.8e3a7bf607f78309@sample.mailgun.com']);
        $testByProviderId = TestSend::factory()->create(['driver' => 'mailgun', 'provider_message_id' => '20260205213049.8e3a7bf607f78309@sample.mailgun.com']);

        expect(postWebhook(mailgunWebhook(mailgunEvent('opened', ['id' => 'by-uuid', 'user-variables' => ['campaign-message' => strtoupper($byUuid->uuid)]]))))->toBe(200)
            // No variables ([]), and a variable another sender set that is
            // no uuid at all: PostgreSQL would refuse it in a uuid column.
            ->and(postWebhook(mailgunWebhook(mailgunEvent('permanent-failure', ['id' => 'by-provider-id']))))->toBe(200)
            ->and(postWebhook(mailgunWebhook(mailgunEvent('permanent-failure', ['id' => 'not-a-uuid', 'user-variables' => ['campaign-message' => 'order-1234']]))))->toBe(200)
            ->and(postWebhook(mailgunWebhook(mailgunEvent('delivered', ['id' => 'test-by-uuid', 'user-variables' => ['campaign-test' => $testSend->uuid]]))))->toBe(200)
            ->and(postWebhook(mailgunWebhook(mailgunEvent('clicked', ['id' => 'test-by-provider-id']))))->toBe(200);

        $events = ProviderEvent::query()->get()->keyBy('provider_event_id');

        expect($events->keys()->sort()->values()->all())->toBe(['by-provider-id', 'by-uuid', 'not-a-uuid', 'test-by-provider-id', 'test-by-uuid'])
            ->and($events['by-uuid']->message_id)->toBe($byUuid->id)
            ->and($events['by-provider-id']->message_id)->toBe($byProviderId->id)
            ->and($events['not-a-uuid']->message_id)->toBe($byProviderId->id)
            ->and($events['test-by-uuid']->test_send_id)->toBe($testSend->id)
            ->and($events['test-by-uuid']->message_id)->toBeNull()
            ->and($events['test-by-provider-id']->test_send_id)->toBe($testByProviderId->id);
    })->with('databases');

    it('drops events about mail the package didn\'t send, and still spends their token', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();

        // A forward through the domain's route: no variables, an unknown id.
        $forward = mailgunWebhook(mailgunEvent('delivered', ['flags' => ['is-routed' => true]]), token: 'forward-token');
        $forged = mailgunWebhook(eventFor($message, 'complained'), token: 'forward-token');

        expect(postWebhook($forward))->toBe(200)
            ->and(ProviderEvent::query()->count())->toBe(0);

        // Recorded in the database, so a release's cache flush can't free it.
        Cache::flush();

        expect(postWebhook($forged))->toBe(406)
            ->and(postWebhook(mailgunWebhook(mailgunEvent('delivered', ['event' => 'stored', 'id' => 'stored-event']))))->toBe(200)
            // A time Carbon can't place: dropped, not a 500 Mailgun retries.
            ->and(postWebhook(mailgunWebhook(eventFor($message, 'delivered', ['timestamp' => 1e30]))))->toBe(200)
            ->and(ProviderEvent::query()->count())->toBe(0);

        Queue::assertNothingPushed();
    })->with('databases');
});

describe('what is kept', function (): void {
    it('keeps what effects and reports read, and never the recipient\'s address or IP', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create(['email' => 'badrecipient@sample.mailgun.com']);

        $failure = eventFor($message, 'permanent-failure', [
            'delivery-status' => ['message' => '5.1.1 <badrecipient@sample.mailgun.com>: Recipient address rejected'],
        ]);

        expect(postWebhook(mailgunWebhook($failure)))->toBe(200);

        $event = ProviderEvent::query()->sole();
        $stored = json_encode($event->payload);

        expect($event->type)->toBe(FeedbackType::Failed)
            ->and($event->is_bot)->toBeFalse()
            ->and($event->payload)->toEqual([
                'severity' => 'permanent',
                'reason' => 'bounce',
                'failure' => 'hard_bounce',
                'code' => 550,
                'provider_message_id' => '20260212174255.58bbb7ce85a423e5@mailgun.com',
                'details' => [
                    'delivery-status' => [
                        'attempt-no' => 1,
                        'bounce-type' => 'hard',
                        'enhanced-code' => '5.5.0',
                        'message' => '5.1.1 <[address]>: Recipient address rejected',
                    ],
                    'recipient-provider' => 'Other',
                    'is-test-mode' => false,
                    'is-routed' => false,
                ],
            ])
            ->and(str_contains($stored, 'badrecipient'))->toBeFalse()
            ->and(str_contains($stored, '161.38.194.10'))->toBeFalse();
    })->with('databases');

    it('keeps a click\'s link without a token or an address in it, and marks machines', function (string $driver): void {
        useDatabase($driver);
        $message = CampaignMessage::factory()->create();
        $click = fn (string $id, string $url): array => eventFor($message, 'clicked', ['id' => $id, 'url' => $url, 'recipient' => '[REDACTED]']);

        expect(postWebhook(mailgunWebhook($click('own-page', 'https://example.com/campaigns/u/'.Token::generate().'?utm_source=newsletter'))))->toBe(200)
            ->and(postWebhook(mailgunWebhook($click('with-address', 'https://shop.example.com/offer?email=reader%40example.com&utm_medium=email'))))->toBe(200)
            ->and(postWebhook(mailgunWebhook(eventFor($message, 'opened', ['id' => 'apple', 'client-info' => ['bot' => 'apple']]))))->toBe(200)
            ->and(postWebhook(mailgunWebhook(eventFor($message, 'opened', ['id' => 'gmail', 'client-info' => ['bot' => 'gmail']]))))->toBe(200);

        $events = ProviderEvent::query()->get()->keyBy('provider_event_id');
        $stored = json_encode($events->pluck('payload'));

        expect($events['own-page']->payload['url'])->toBe('https://example.com/campaigns/u/{token}?utm_source=newsletter')
            ->and($events['with-address']->payload['url'])->toBe('https://shop.example.com/offer?email=[address]&utm_medium=email')
            ->and($events['own-page']->payload['details']['client-info'])->toEqual([
                'client-name' => 'Chrome Mobile',
                'client-os' => 'Android',
                'client-type' => 'mobile browser',
                'device-type' => 'mobile',
                'user-agent' => 'Mozilla/5.0 (Linux; Android 9; itel A27) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.6943.143 Mobile Safari/537.36',
            ])
            ->and($events['own-page']->payload['details']['delivered-at'])->toEqual(1770327049)
            ->and($events['own-page']->is_bot)->toBeFalse()
            ->and($events['apple']->is_bot)->toBeTrue()
            ->and($events['apple']->payload['bot'])->toBe('apple')
            // Gmail's proxy loads images when the person opens the email.
            ->and($events['gmail']->is_bot)->toBeFalse()
            ->and(str_contains($stored, '9.169.124.21'))->toBeFalse()
            ->and(str_contains($stored, 'San Antonio'))->toBeFalse();
    })->with('databases');
});

describe('processing', function (): void {
    it('marks an event processed once, whatever runs it again', function (string $driver): void {
        useDatabase($driver);
        $event = ProviderEvent::factory()->create();

        app()->call([new ProcessFeedbackJob($event->id), 'handle']);
        $processedAt = $event->fresh()?->processed_at;

        $this->travel(5)->minutes();
        app()->call([new ProcessFeedbackJob($event->id), 'handle']);
        app()->call([new ProcessFeedbackJob(0), 'handle']);

        expect($processedAt)->not->toBeNull()
            ->and($event->fresh()?->processed_at?->equalTo($processedAt))->toBeTrue();
    })->with('databases');

    it('carries a Mailgun bounce through to its effects: the email failed and the address blocked', function (): void {
        $subscriber = Subscriber::factory()->create();
        $message = CampaignMessage::factory()->for($subscriber)->create(['driver' => 'mailgun', 'status' => MessageStatus::Sent, 'sent_at' => now()->subHour()]);

        expect(postWebhook(mailgunWebhook(eventFor($message, 'permanent-failure'))))->toBe(200);

        $event = ProviderEvent::query()->sole();
        app()->call([new ProcessFeedbackJob($event->id), 'handle']);

        expect($event->driver)->toBe('mailgun')
            ->and($message->fresh())->status->toBe(MessageStatus::Failed)->failure_reason->toBe('hard_bounce')->failure_code->toBe(550)
            ->and($subscriber->fresh()?->blocked_reason)->toBe(BlockReason::HardBounce);
    });
});
