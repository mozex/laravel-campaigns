<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
use Mozex\Campaigns\Actions\FreezeReportAction;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\UnsubscribeAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Reports\CampaignReport;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeSendOnlyDriver;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-03 12:00:00'));
});

/**
 * One of the campaign's messages, to no subscriber.
 *
 * @param  array<string, mixed>  $attributes
 */
function reportMessage(Campaign $campaign, array $attributes): CampaignMessage
{
    return CampaignMessage::factory()->for($campaign)->create(['subscriber_id' => null, 'email' => null, ...$attributes]);
}

/**
 * The opt-out an unsubscribe from the message wrote.
 */
function unsubscribedThrough(CampaignMessage $message, string $source, ConsentAction $action = ConsentAction::OptOut): void
{
    ConsentRecord::factory()->create(['subscriber_id' => null, 'action' => $action, 'source' => $source, 'meta' => ['message' => $message->uuid]]);
}

/**
 * Seven messages and their events, every kind of number among them.
 */
function reportedCampaign(): Campaign
{
    $readers = CampaignList::factory()->create(['key' => 'readers']);
    $buyers = CampaignList::factory()->create(['key' => 'buyers']);
    $step = FreezeCampaignAction::TIER_STEP;

    $campaign = Campaign::factory()->create([
        'key' => 'spring',
        'name' => 'Spring sale',
        'status' => CampaignStatus::Completed,
        'recipient_count' => 7,
        'started_at' => '2026-03-01 10:00:00',
        'completed_at' => '2026-03-02 07:00:00',
    ]);

    $read = reportMessage($campaign, [
        'email' => 'ada@gmail.com', 'locale' => 'en', 'position' => 0, 'list_ids' => [$readers->id],
        'status' => MessageStatus::Delivered, 'sent_at' => '2026-03-01 10:00:00', 'delivered_at' => '2026-03-01 10:01:00',
        'opened_at' => '2026-03-01 12:00:00', 'clicked_at' => '2026-03-02 09:00:00',
    ]);
    reportMessage($campaign, [
        'email' => 'bo@outlook.com', 'locale' => 'fr', 'position' => $step, 'list_ids' => [$readers->id, $buyers->id],
        'status' => MessageStatus::Failed, 'sent_at' => '2026-03-01 10:00:00', 'failed_at' => '2026-03-01 10:05:00', 'failure_reason' => 'hard_bounce',
    ]);
    $complained = reportMessage($campaign, [
        'email' => 'cy@yahoo.com', 'locale' => null, 'position' => 2 * $step, 'list_ids' => [$buyers->id],
        'status' => MessageStatus::Delivered, 'sent_at' => '2026-03-01 11:00:00', 'delivered_at' => '2026-03-01 11:01:00',
        'complained_at' => '2026-03-01 20:00:00', 'unsubscribed_at' => '2026-03-01 20:00:00',
    ]);
    // Its person was forgotten, the address with them.
    $forgotten = reportMessage($campaign, [
        'locale' => 'en', 'position' => 1, 'list_ids' => [$readers->id],
        'status' => MessageStatus::Delivered, 'sent_at' => '2026-03-02 07:00:00', 'delivered_at' => '2026-03-02 07:01:00',
        'unsubscribed_at' => '2026-03-02 10:00:00',
    ]);
    reportMessage($campaign, [
        'email' => 'di@icloud.com', 'locale' => 'en', 'position' => 2, 'list_ids' => [$readers->id],
        'status' => MessageStatus::Failed, 'sent_at' => '2026-03-02 07:00:00', 'failed_at' => '2026-03-02 07:02:00', 'failure_reason' => 'sender_blocked',
    ]);
    reportMessage($campaign, ['email' => 'ed@example.org', 'locale' => 'en', 'position' => 3, 'list_ids' => [$readers->id], 'status' => MessageStatus::Pending]);
    reportMessage($campaign, ['email' => 'fay@gmail.com', 'locale' => 'en', 'position' => 4, 'list_ids' => [999], 'status' => MessageStatus::Skipped]);

    $event = fn (CampaignMessage $message, FeedbackType $type, bool $bot, ?string $url = null): ProviderEvent => ProviderEvent::factory()->for($message, 'message')
        ->create(['type' => $type, 'is_bot' => $bot, 'payload' => $url === null ? null : ['url' => $url]]);

    $event($read, FeedbackType::Opened, false);
    $event($read, FeedbackType::Opened, false);
    $event($read, FeedbackType::Opened, true);
    $event($complained, FeedbackType::Opened, true);
    $event($read, FeedbackType::Clicked, false, 'https://example.com/sale');
    $event($read, FeedbackType::Clicked, true, 'https://example.com/sale');
    $event($read, FeedbackType::Clicked, true, 'https://example.com/terms');
    $event($read, FeedbackType::Delivered, false);

    // Subscribing again from the same email, and a spam report's own
    // opt-out, name the email too but aren't how it unsubscribed.
    unsubscribedThrough($complained, UnsubscribeAction::PAGE, ConsentAction::OptIn);
    unsubscribedThrough($complained, 'complaint');
    unsubscribedThrough($complained, UnsubscribeAction::ONE_CLICK);
    unsubscribedThrough($forgotten, UnsubscribeAction::PAGE);

    return $campaign;
}

it('counts a campaign\'s messages and events, every breakdown and day included', function (string $driver): void {
    // The messages are read as plain rows, so each database's own way of
    // handing back JSON and times is read here.
    useDatabase($driver);
    $report = CampaignReport::build(reportedCampaign());

    $row = fn (int $messages, int $sends = 0, int $delivered = 0, int $hard = 0, int $senderBlocked = 0, int $complaints = 0, int $unsubscribes = 0, int $opened = 0, int $clicked = 0): array => [
        'messages' => $messages, 'sends' => $sends, 'delivered' => $delivered, 'hard_bounces' => $hard, 'sender_blocked' => $senderBlocked,
        'complaints' => $complaints, 'unsubscribes' => $unsubscribes, 'opened' => $opened, 'clicked' => $clicked,
    ];

    expect($report)
        ->campaign->toBe([
            'key' => 'spring', 'name' => 'Spring sale', 'status' => 'completed', 'recipients' => 7,
            'started_at' => '2026-03-01T10:00:00+00:00', 'completed_at' => '2026-03-02T07:00:00+00:00', 'canceled_at' => null,
        ])
        ->messages->toBe(7)
        ->statuses->toBe(['pending' => 1, 'queued' => 0, 'sending' => 0, 'sent' => 0, 'delivered' => 3, 'failed' => 2, 'skipped' => 1, 'unknown' => 0])
        ->sends->toBe(5)
        ->unsent->toBe(1)
        ->delivered->toBe(3)
        ->failures->toBe(['hard_bounce' => 1, 'sender_blocked' => 1])
        ->hard_bounces->toBe(1)
        ->complaints->toBe(1)
        ->unsubscribes->toBe(['total' => 2, 'one_click' => 1, 'page' => 1, 'provider' => 0, 'other' => 0])
        ->opens->toBe(['people' => 1, 'human' => 2, 'machine' => 2])
        ->clicks->toBe(['people' => 1, 'human' => 1, 'machine' => 2])
        ->rates->toBe(['delivery' => 60.0, 'hard_bounce' => 20.0, 'complaint' => 20.0, 'unsubscribe' => 40.0, 'open' => 33.333, 'click' => 33.333, 'basis' => 'delivered'])
        ->links->toBe([
            ['url' => 'https://example.com/sale', 'people' => 1, 'human' => 1, 'machine' => 1],
            ['url' => 'https://example.com/terms', 'people' => 0, 'human' => 0, 'machine' => 1],
        ])
        ->by_list->toBe([
            '#999' => $row(1),
            'buyers' => $row(2, sends: 2, delivered: 1, hard: 1, complaints: 1, unsubscribes: 1),
            'readers' => $row(5, sends: 4, delivered: 2, hard: 1, senderBlocked: 1, unsubscribes: 1, opened: 1, clicked: 1),
        ])
        ->by_locale->toBe([
            'en' => $row(5, sends: 3, delivered: 2, senderBlocked: 1, unsubscribes: 1, opened: 1, clicked: 1),
            'fr' => $row(1, sends: 1, hard: 1),
            'none' => $row(1, sends: 1, delivered: 1, complaints: 1, unsubscribes: 1),
        ])
        ->and(array_map(fn (array $counts): int => $counts['messages'], $report['by_tier']))->toBe(['engaged' => 5, 'active' => 1, 'silent' => 1])
        ->and(array_map(fn (array $counts): int => $counts['messages'], $report['by_provider']))
        ->toBe(['gmail' => 2, 'microsoft' => 1, 'yahoo' => 1, 'apple' => 1, 'other' => 1, 'unknown' => 1])
        ->and($report['by_provider']['apple']['sender_blocked'])->toBe(1)
        ->and($report['timeline'])->toBe([
            '2026-03-01' => ['sent' => 3, 'delivered' => 2, 'failed' => 1, 'hard_bounces' => 1, 'opened' => 1, 'clicked' => 0, 'complaints' => 1, 'unsubscribes' => 1],
            '2026-03-02' => ['sent' => 2, 'delivered' => 1, 'failed' => 1, 'hard_bounces' => 0, 'opened' => 0, 'clicked' => 1, 'complaints' => 0, 'unsubscribes' => 1],
        ])
        ->and($report['computed_at'])->toBe('2026-03-03T12:00:00+00:00')
        ->and($report['frozen_at'])->toBeNull();

    // Days are the app's: 20:00 UTC on the 1st is the 2nd in Tokyo.
    config()->set('app.timezone', 'Asia/Tokyo');

    $timeline = CampaignReport::build(Campaign::query()->where('key', 'spring')->sole())['timeline'];

    expect($timeline['2026-03-01']['complaints'])->toBe(0)
        ->and($timeline['2026-03-02']['complaints'])->toBe(1)
        ->and($timeline['2026-03-02']['unsubscribes'])->toBe(2);
})->with('databases');

it('reads how each unsubscribe came on every database', function (string $driver): void {
    useDatabase($driver);

    $campaign = Campaign::factory()->create();
    $at = ['status' => MessageStatus::Delivered, 'sent_at' => now()->subDay(), 'unsubscribed_at' => now()];

    unsubscribedThrough(reportMessage($campaign, $at), UnsubscribeAction::ONE_CLICK);
    unsubscribedThrough(reportMessage($campaign, $at), UnsubscribeAction::PROVIDER);
    // An unsubscribe an import wrote, with no record naming the email.
    reportMessage($campaign, $at);
    // Another campaign's.
    unsubscribedThrough(reportMessage(Campaign::factory()->create(), $at), UnsubscribeAction::PAGE);

    expect(CampaignReport::build($campaign)['unsubscribes'])->toBe(['total' => 3, 'one_click' => 1, 'page' => 0, 'provider' => 1, 'other' => 1]);
})->with('databases');

it('freezes a finished campaign\'s report once, which then stays as it was', function (string $driver): void {
    useDatabase($driver);
    $campaign = reportedCampaign();

    expect(app(FreezeReportAction::class)->execute($campaign))->toBeTrue()
        ->and(app(FreezeReportAction::class)->execute($campaign->fresh()))->toBeFalse();

    $frozen = $campaign->fresh()->report;

    // A late bounce and an open after the freeze change nothing in it.
    $this->travel(1)->day();
    CampaignMessage::query()->where('campaign_id', $campaign->id)->where('status', MessageStatus::Delivered)->limit(1)->update(['status' => MessageStatus::Failed]);
    ProviderEvent::factory()->for(CampaignMessage::query()->where('campaign_id', $campaign->id)->firstOrFail(), 'message')->create(['type' => FeedbackType::Opened]);

    $report = CampaignReport::for($campaign->fresh());

    // Each database hands the JSON's keys back in its own order.
    expect($frozen)
        ->frozen_at->toBe('2026-03-03T12:00:00+00:00')
        ->delivered->toBe(3)
        ->opens->toEqual(['people' => 1, 'human' => 2, 'machine' => 2])
        ->clicks->toEqual(['people' => 1, 'human' => 1, 'machine' => 2])
        ->unsubscribes->toEqual(['total' => 2, 'one_click' => 1, 'page' => 1, 'provider' => 0, 'other' => 0])
        ->links->toEqual([
            ['url' => 'https://example.com/sale', 'people' => 1, 'human' => 1, 'machine' => 1],
            ['url' => 'https://example.com/terms', 'people' => 0, 'human' => 0, 'machine' => 1],
        ])
        ->and($report)->toEqual([...$frozen, 'queue' => CampaignReport::queue($campaign)])
        ->and(CampaignReport::build($campaign->fresh()))->delivered->toBe(2);
})->with('databases');

it('freezes only a completed or cancelled campaign\'s report', function (CampaignStatus $status): void {
    $campaign = Campaign::factory()->create(['status' => $status]);

    expect(fn (): bool => app(FreezeReportAction::class)->execute($campaign))
        ->toThrow(CampaignsException::class, "A {$status->value} campaign's report can't be frozen")
        ->and($campaign->fresh()->report)->toBeNull();
})->with([CampaignStatus::Sending, CampaignStatus::Paused, CampaignStatus::Draft]);

it('shows where sending stands now', function (): void {
    config()->set('campaigns.enabled', false);
    app(PumpCampaignsAction::class)->execute();

    $this->travel(10)->minutes();

    $campaign = Campaign::factory()->sending()->create();
    reportMessage($campaign, ['status' => MessageStatus::Pending]);
    reportMessage($campaign, ['status' => MessageStatus::Pending]);
    reportMessage($campaign, ['status' => MessageStatus::Queued, 'claimed_at' => now()->subMinutes(8)]);
    reportMessage($campaign, ['status' => MessageStatus::Queued, 'claimed_at' => now()->subMinutes(6)]);
    reportMessage($campaign, ['status' => MessageStatus::Queued, 'claimed_at' => now()->subMinutes(2)]);

    expect(CampaignReport::for($campaign)['queue'])->toBe([
        'pump_last_run' => '2026-03-03T12:00:00+00:00',
        'pending' => 2,
        'queued' => 3,
        'stalled' => 2,
        'oldest_queued_at' => '2026-03-03T12:02:00+00:00',
        'jobs_waiting' => 0,
    ]);

    cache()->flush();

    expect(CampaignReport::queue($campaign)['pump_last_run'])->toBeNull();

    // Redis keeps a number unserialized and gives it back as a string.
    cache()->forever(PumpCampaignsAction::LAST_RUN, (string) now()->subMinute()->getTimestamp());

    expect(CampaignReport::queue($campaign)['pump_last_run'])->toBe('2026-03-03T12:09:00+00:00');
});

it('divides opens and clicks by the emails sent through a driver that reads nothing back, and says so', function (): void {
    $campaign = Campaign::factory()->sending()->create();
    app(DriverManager::class)->swap('fake', new FakeSendOnlyDriver);
    reportMessage($campaign, ['status' => MessageStatus::Sent, 'sent_at' => now(), 'opened_at' => now()]);
    reportMessage($campaign, ['status' => MessageStatus::Sent, 'sent_at' => now()]);
    reportMessage($campaign, ['status' => MessageStatus::Sent, 'sent_at' => now(), 'opened_at' => now(), 'clicked_at' => now()]);
    // Refused at send for a mailbox that doesn't exist: sent, never reached.
    reportMessage($campaign, ['status' => MessageStatus::Failed, 'sent_at' => now(), 'failed_at' => now(), 'failure_reason' => 'hard_bounce']);

    $sendOnly = CampaignReport::build($campaign);

    // After a switch, one delivered through a driver that reads back.
    reportMessage($campaign, ['status' => MessageStatus::Delivered, 'driver' => 'mailgun', 'sent_at' => now(), 'delivered_at' => now(), 'opened_at' => now()]);
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');

    $mixed = CampaignReport::build($campaign);

    // Its key gone, Mailgun reads nothing back any more, but what it
    // delivered still counts as delivered.
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', null);

    $unconfigured = CampaignReport::build($campaign);

    expect($sendOnly['rates'])->toBe(['delivery' => null, 'hard_bounce' => 25.0, 'complaint' => 0.0, 'unsubscribe' => 0.0, 'open' => 66.667, 'click' => 33.333, 'basis' => 'sent'])
        ->and($sendOnly['statuses']['sent'])->toBe(3)
        ->and($sendOnly['delivered'])->toBe(0)
        ->and($mixed['rates'])->toBe(['delivery' => 100.0, 'hard_bounce' => 20.0, 'complaint' => 0.0, 'unsubscribe' => 0.0, 'open' => 75.0, 'click' => 25.0, 'basis' => 'delivered_or_sent'])
        ->and($unconfigured['rates'])->toBe($mixed['rates']);
});

/**
 * A click on the message's link, a person's or a machine's.
 */
function clickOn(CampaignMessage $message, string $url, bool $bot = false): void
{
    ProviderEvent::factory()->for($message, 'message')->create(['type' => FeedbackType::Clicked, 'is_bot' => $bot, 'payload' => ['url' => $url]]);
}

it('counts the people who clicked each link apart from its raw clicks', function (string $driver): void {
    // The clicks are read as plain rows, their JSON as each database hands
    // it back.
    useDatabase($driver);
    $campaign = Campaign::factory()->create();
    $first = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour(), 'clicked_at' => now()]);
    $second = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour(), 'clicked_at' => now()]);
    $scanned = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);

    // A person's click and a mail app's fetch of it again a moment later,
    // both stored as a person's.
    clickOn($first, 'https://example.com/sale');
    clickOn($first, 'https://example.com/sale');
    clickOn($second, 'https://example.com/sale');
    // A scanner opening every link counts as a machine only.
    clickOn($scanned, 'https://example.com/sale', bot: true);
    clickOn($scanned, 'https://example.com/terms', bot: true);

    expect(CampaignReport::build($campaign))
        ->clicks->toBe(['people' => 2, 'human' => 3, 'machine' => 2])
        ->links->toBe([
            ['url' => 'https://example.com/sale', 'people' => 2, 'human' => 3, 'machine' => 1],
            ['url' => 'https://example.com/terms', 'people' => 0, 'human' => 0, 'machine' => 1],
        ]);
})->with('databases');

it('ranks links by the people who clicked them before their raw clicks', function (): void {
    $campaign = Campaign::factory()->create();
    $one = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);
    $two = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);

    // One reader clicking a link three times, two readers another once each.
    clickOn($one, 'https://example.com/again');
    clickOn($one, 'https://example.com/again');
    clickOn($one, 'https://example.com/again');
    clickOn($one, 'https://example.com/shared');
    clickOn($two, 'https://example.com/shared');

    expect(CampaignReport::build($campaign)['links'])->toBe([
        ['url' => 'https://example.com/shared', 'people' => 2, 'human' => 2, 'machine' => 0],
        ['url' => 'https://example.com/again', 'people' => 1, 'human' => 3, 'machine' => 0],
    ]);
});

it('ranks links with as many people by their raw clicks, human then machine, then by address', function (): void {
    $campaign = Campaign::factory()->create();
    $one = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);
    $two = reportMessage($campaign, ['status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);

    // One person each; the later addresses have more clicks.
    clickOn($one, 'https://example.com/a');
    clickOn($two, 'https://example.com/b');
    clickOn($two, 'https://example.com/b');
    clickOn($one, 'https://example.com/c');
    clickOn($one, 'https://example.com/d');
    clickOn($two, 'https://example.com/d', bot: true);
    clickOn($one, 'https://example.com/e');

    expect(array_column(CampaignReport::build($campaign)['links'], 'url'))
        ->toBe(['https://example.com/b', 'https://example.com/d', 'https://example.com/a', 'https://example.com/c', 'https://example.com/e']);
});

it('adds up the people who clicked a link across every block of emails it reads', function (): void {
    $campaign = Campaign::factory()->create();
    $messages = CampaignMessage::factory()->for($campaign)->count(CampaignReport::CHUNK + 1)
        ->create(['subscriber_id' => null, 'email' => null, 'status' => MessageStatus::Delivered, 'sent_at' => now()->subHour()]);

    // The first email and the last are read in different blocks.
    clickOn($messages->first(), 'https://example.com/sale');
    clickOn($messages->last(), 'https://example.com/sale');
    clickOn($messages->last(), 'https://example.com/sale');

    expect(CampaignReport::build($campaign)['links'])->toBe([['url' => 'https://example.com/sale', 'people' => 2, 'human' => 3, 'machine' => 0]]);
});
