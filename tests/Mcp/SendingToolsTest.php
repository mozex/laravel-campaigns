<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\Content\EmailSource;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Mcp\Schemas;
use Mozex\Campaigns\Mcp\Tools\CampaignsTool;
use Mozex\Campaigns\Mcp\Tools\CancelCampaignTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignReportTool;
use Mozex\Campaigns\Mcp\Tools\GetCampaignStatusTool;
use Mozex\Campaigns\Mcp\Tools\PauseCampaignTool;
use Mozex\Campaigns\Mcp\Tools\PreviewAudienceTool;
use Mozex\Campaigns\Mcp\Tools\ResumeCampaignTool;
use Mozex\Campaigns\Mcp\Tools\SlowDownCampaignTool;
use Mozex\Campaigns\Mcp\Tools\SpeedUpCampaignTool;
use Mozex\Campaigns\Mcp\Tools\StartCampaignTool;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Reports\CampaignReport;
use Mozex\Campaigns\Reports\GmailReading;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Reports\ReportSummary;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\Drivers\FakeSendOnlyDriver;
use Mozex\Campaigns\Sending\Pace;
use Mozex\Campaigns\Sending\Usage;

beforeEach(function (): void {
    sendFromExample();
    config(['campaigns.driver' => 'fake']);
});

/**
 * A draft on a list of its own, of $people new subscribers.
 */
function draftFor(int $people, string $key = 'march'): Campaign
{
    $list = CampaignList::factory()->create(['key' => "{$key}-readers"]);

    foreach (range(1, $people) as $n) {
        subscriberOn([$list], ['email' => "{$key}-{$n}@example.org"]);
    }

    return app(CreateCampaignAction::class)->execute($key, 'March', EmailSource::contents([campaignEmail()]), ['lists' => [$list->key]])->campaign;
}

it('starts a draft with the count its audience preview gave, answering at once while the freeze runs', function (string $driver): void {
    useDatabase($driver);
    Queue::fake([FreezeCampaignJob::class, SendMessageJob::class]);
    draftFor(3);

    $recipients = structuredContent(agent()->tool(PreviewAudienceTool::class, ['campaign' => 'march'])->assertOk())['recipients'];
    $started = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => $recipients, 'pace' => ['per_minute' => 2]])->assertOk());

    expect($recipients)->toBe(3)
        ->and($started['campaign']['status'])->toBe('starting')
        ->and($started['campaign']['pace']['per_minute'])->toBe(2);

    Queue::pushed(FreezeCampaignJob::class)->first()->handle(app(FreezeCampaignAction::class));

    $status = structuredContent(agent(Rights::Read)->tool(GetCampaignStatusTool::class, ['key' => 'march'])->assertOk())['campaign'];

    expect($status)->toMatchArray(['status' => 'sending', 'recipient_count' => 3, 'start_error' => null, 'sends' => 0, 'stalled' => false])
        ->and($status['messages'])->toMatchArray(['pending' => 3, 'sent' => 0])
        ->and($status['pace'])->toMatchArray(['per_minute' => 2, 'day' => 1, 'day_volume' => 100, 'in_sending_hours' => true, 'allowance_now' => 2]);
})->with('databases');

it('hands a start back to a draft, saying why, when the count differs from the audience', function (): void {
    draftFor(2);

    $started = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 3])->assertOk());

    expect($started['campaign']['status'])->toBe('draft')
        ->and($started['campaign']['start_error'])->toContain('3')
        ->and(CampaignMessage::query()->count())->toBe(0);

    agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 0])->assertHasErrors(['recipients']);
    agent()->tool(StartCampaignTool::class, ['key' => 'march'])->assertHasErrors(['recipients']);
});

it('schedules a start for a time given with its offset, and refuses one without', function (): void {
    $this->freezeTime();
    draftFor(2);

    foreach ([
        now()->addDay()->format('Y-m-d\TH:i:s'),
        '2026-02-30T09:00:00Z',
        'tomorrow at nine',
    ] as $at) {
        agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 2, 'at' => $at])->assertHasErrors(['The time ']);
    }

    $at = now()->addDay()->setTimezone('Asia/Tokyo');
    $scheduled = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 2, 'at' => $at->toIso8601String()])->assertOk())['campaign'];

    expect($scheduled['status'])->toBe('scheduled')
        ->and($scheduled['scheduled_at'])->not->toBeNull()
        ->and(Campaign::forKey('march')->scheduled_at?->getTimestamp())->toBe($at->getTimestamp());

    // A time that passed while the start waited for approval starts it now;
    // one further back is likelier a mistyped date than a wish to send.
    draftFor(1, 'april');
    draftFor(1, 'may');

    agent()->tool(StartCampaignTool::class, ['key' => 'april', 'recipients' => 1, 'at' => now()->subMinutes(16)->toIso8601String()])
        ->assertHasErrors(['more than 15 minutes in the past']);

    $now = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'may', 'recipients' => 1, 'at' => now()->subMinutes(10)->toIso8601String()])->assertOk())['campaign'];

    expect($now)->toMatchArray(['status' => 'sending', 'scheduled_at' => null])
        ->and(Campaign::forKey('april')->status)->toBe(CampaignStatus::Draft);

    config(['campaigns.enabled' => false]);

    agent()->tool(StartCampaignTool::class, ['key' => 'april', 'recipients' => 1])->assertHasErrors(['switched off']);
});

it('pauses, resumes, slows down, speeds up and cancels a campaign, stopping and slowing even while sending is off', function (): void {
    Queue::fake([SendMessageJob::class]);
    sendingCampaign(3, ['per_minute' => 5], key: 'march');

    config(['campaigns.enabled' => false]);

    $paused = structuredContent(agent()->tool(PauseCampaignTool::class, ['key' => 'march', 'reason' => 'Checking a link.'])->assertOk())['campaign'];
    $slower = structuredContent(agent()->tool(SlowDownCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 3]])->assertOk())['campaign'];

    agent()->tool(ResumeCampaignTool::class, ['key' => 'march'])->assertHasErrors(['switched off']);
    agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 8]])->assertHasErrors(['switched off']);
    agent()->tool(SlowDownCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 8]])->assertHasErrors(['faster']);

    config(['campaigns.enabled' => true]);

    $faster = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 8]])->assertOk())['campaign'];
    $resumed = structuredContent(agent()->tool(ResumeCampaignTool::class, ['key' => 'march'])->assertOk())['campaign'];
    $canceled = structuredContent(agent()->tool(CancelCampaignTool::class, ['key' => 'march'])->assertOk())['campaign'];

    expect($paused)->toMatchArray(['status' => 'paused', 'pause_reason' => 'Checking a link.'])
        ->and($slower['pace']['per_minute'])->toBe(3)
        ->and($faster['pace']['per_minute'])->toBe(8)
        ->and($resumed)->toMatchArray(['status' => 'sending', 'pause_reason' => null])
        ->and($canceled['status'])->toBe('canceled')
        ->and($canceled['messages']['pending'])->toBe(3);

    // Stopping twice changes nothing; a cancelled campaign never pauses.
    agent()->tool(CancelCampaignTool::class, ['key' => 'march'])->assertOk();
    agent()->tool(PauseCampaignTool::class, ['key' => 'march'])->assertHasErrors(['canceled']);
    agent()->tool(PauseCampaignTool::class, ['key' => 'nope'])->assertHasErrors(['nope']);
});

it('shows where sending stands: failures by kind, unclear sends, a stall and the pace in effect', function (string $driver): void {
    useDatabase($driver);
    $this->freezeTime();
    Queue::fake([SendMessageJob::class]);
    config(['campaigns.pace.per_minute' => 6]);
    $campaign = sendingCampaign(7, key: 'march');
    $campaign->forceFill(['thresholds' => ['complaints' => 1], 'rate_halved_until' => now()->addMinutes(10)])->save();

    $messages = CampaignMessage::query()->where('campaign_id', $campaign->id)->orderBy('id')->get();
    $messages[0]->forceFill(['status' => MessageStatus::Failed, 'sent_at' => now(), 'failed_at' => now(), 'failure_reason' => 'hard_bounce'])->save();
    $messages[1]->forceFill(['status' => MessageStatus::Failed, 'sent_at' => now(), 'failed_at' => now(), 'failure_reason' => 'hard_bounce'])->save();
    $messages[2]->forceFill(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'not_accepted'])->save();
    $messages[3]->forceFill(['status' => MessageStatus::Unknown, 'failure_reason' => 'unclear'])->save();
    $messages[4]->forceFill(['status' => MessageStatus::Queued, 'claim_id' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(10)])->save();

    $status = structuredContent(agent(Rights::Read, person: true)->tool(GetCampaignStatusTool::class, ['key' => 'march'])->assertOk())['campaign'];

    expect($status['failures'])->toBe(['hard_bounce' => 2, 'not_accepted' => 1])
        ->and($status['messages'])->toMatchArray(['failed' => 3, 'unknown' => 1, 'queued' => 1, 'pending' => 2])
        ->and($status['sends'])->toBe(2)
        ->and($status['stalled'])->toBeTrue()
        ->and($status['queue']['stalled'])->toBe(1)
        ->and($status['pace'])->toMatchArray([
            'per_minute' => 6,
            'rate_halved_until' => now()->addMinutes(10)->toIso8601String(),
            'in_sending_hours' => true,
        ])
        // The pump hands out nothing while the queue is stalled.
        ->and($status['pace']['allowance_now'])->toBe(0)
        ->and($status['limits']['complaints'])->toBe(1)
        ->and($status['limits']['hard_bounce_rate'])->toEqual(2.0);

    $messages[4]->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()])->save();

    // Halved to 3, of which the claim ten minutes ago used none this minute.
    expect(structuredContent(agent(Rights::Read)->tool(GetCampaignStatusTool::class, ['key' => 'march']))['campaign']['pace']['allowance_now'])->toBe(3);

    config(['campaigns.enabled' => false]);

    expect(structuredContent(agent(Rights::Read)->tool(GetCampaignStatusTool::class, ['key' => 'march']))['campaign']['pace']['allowance_now'])->toBe(0);
})->with('databases');

it('describes every pace setting the pace takes', function (): void {
    foreach (Pace::KEYS as $key) {
        expect(str_contains(Schemas::PACE, $key))->toBeTrue("Schemas::PACE doesn't describe {$key}.");
    }
});

it('reads a campaign\'s report through the tool, its queue as it is now', function (string $driver): void {
    useDatabase($driver);
    Queue::fake([SendMessageJob::class]);
    $campaign = sendingCampaign(2, key: 'march');
    CampaignMessage::query()->where('campaign_id', $campaign->id)->update(['status' => MessageStatus::Delivered, 'sent_at' => now(), 'delivered_at' => now()]);
    $message = CampaignMessage::query()->where('campaign_id', $campaign->id)->first();

    // More links clicked than the report keeps.
    foreach (range(1, CampaignReport::LINKS + 1) as $n) {
        ProviderEvent::factory()->for($message, 'message')->create(['type' => FeedbackType::Clicked, 'payload' => ['url' => "https://example.com/{$n}"]]);
    }

    $report = structuredContent(agent(Rights::Read)->tool(GetCampaignReportTool::class, ['key' => 'march'])->assertOk());

    expect($report)->toMatchArray(['messages' => 2, 'sends' => 2, 'delivered' => 2, 'links_total' => CampaignReport::LINKS + 1, 'lists_total' => 1, 'locales_total' => 1, 'days_total' => 1])
        ->and($report['links'])->toHaveCount(ReportSummary::LINKS)
        ->and($report['links'][0])->toBe(['url' => 'https://example.com/1', 'people' => 1, 'human' => 1, 'machine' => 0])
        ->and(app(GetCampaignReportTool::class)->toArray()['outputSchema']['properties']['links']['items']['required'])->toBe(['url', 'people', 'human', 'machine'])
        ->and($report['campaign']['key'])->toBe('march')
        ->and($report['rates']['delivery'])->toEqual(100)
        ->and($report['failures'])->toBe([])
        ->and($report['queue']['pending'])->toBe(0);
})->with('databases');

it('shows a report\'s largest parts with their totals, under the answer limit', function (string $character): void {
    $campaign = draftFor(1);
    $long = fn (int $length, string $prefix = ''): string => $prefix.mb_substr(str_repeat($character, $length), 0, $length - mb_strlen($prefix));
    $row = array_fill_keys(['messages', 'sends', 'delivered', 'hard_bounces', 'sender_blocked', 'complaints', 'unsubscribes', 'opened', 'clicked'], 1_000_000);
    $report = CampaignReport::build($campaign);

    $report['campaign']['name'] = $long(255);
    $report['links'] = array_map(fn (int $n): array => ['url' => $long(2048, "https://example.com/{$n}?"), 'people' => 1_000_000, 'human' => 1_000_000, 'machine' => 1_000_000], range(1, CampaignReport::LINKS));
    $report['links_total'] = 5000;
    $report['by_list'] = [];
    $report['by_locale'] = [];
    $report['timeline'] = [];

    foreach (range(1, 60) as $n) {
        $report['by_list'][str_pad((string) $n, 125, 'k', STR_PAD_LEFT)] = [...$row, 'messages' => $n];
        $report['by_locale'][$long(35, (string) $n)] = [...$row, 'messages' => $n];
    }

    // A key PHP reads as a number, the largest.
    $report['by_list']['2024'] = [...$row, 'messages' => 2_000_000];

    foreach (range(1, 400) as $day) {
        $report['timeline'][now()->subDays(400 - $day)->toDateString()] = array_fill_keys(['sent', 'delivered', 'failed', 'hard_bounces', 'opened', 'clicked', 'complaints', 'unsubscribes'], 1_000_000);
    }

    $report['failures'] = ['hard_bounce' => 1_000_000, 'not_accepted' => 1_000_000];
    $campaign->forceFill(['status' => CampaignStatus::Completed, 'report' => $report])->save();

    $answer = structuredContent(agent(Rights::Read)->tool(GetCampaignReportTool::class, ['key' => 'march'])->assertOk()->assertHasNoErrors());
    $length = mb_strlen((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    expect($length)->toBeLessThan(CampaignsTool::RESULT_LIMIT)
        ->and($answer['links'])->toHaveCount(ReportSummary::LINKS)
        ->and($answer['links_total'])->toBe(5000)
        ->and(mb_strlen($answer['links'][0]['url']))->toBe(ReportSummary::URL_LENGTH + 3)
        ->and($answer['by_list'])->toHaveCount(ReportSummary::BREAKDOWN)
        ->and($answer['lists_total'])->toBe(61)
        // The largest first, a key PHP reads as a number kept as it was.
        ->and(array_slice(array_keys($answer['by_list']), 0, 2))->toBe([2024, str_pad('60', 125, 'k', STR_PAD_LEFT)])
        ->and($answer['by_locale'])->toHaveCount(ReportSummary::BREAKDOWN)
        ->and($answer['locales_total'])->toBe(60)
        ->and($answer['timeline'])->toHaveCount(ReportSummary::DAYS)
        ->and(array_key_last($answer['timeline']))->toBe(now()->toDateString())
        ->and($answer['days_total'])->toBe(400)
        ->and(Campaign::forKey('march')->report['links'])->toHaveCount(CampaignReport::LINKS);
})->with('costly characters');

it('warns on starting and resuming through a driver that reads nothing back, and only then', function (): void {
    sendingCampaign(1, key: 'april', list: 'april-readers');
    agent()->tool(PauseCampaignTool::class, ['key' => 'april'])->assertOk();
    Queue::fake([FreezeCampaignJob::class, SendMessageJob::class]);
    draftFor(1);
    draftFor(1, 'may');

    $quiet = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 1, 'at' => now()->addDay()->toIso8601String()])->assertOk());
    app(DriverManager::class)->swap('fake', new FakeSendOnlyDriver);
    $started = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'may', 'recipients' => 1])->assertOk());
    $resumed = structuredContent(agent()->tool(ResumeCampaignTool::class, ['key' => 'april'])->assertOk());
    $paused = structuredContent(agent()->tool(PauseCampaignTool::class, ['key' => 'april'])->assertOk());

    $sped = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'april', 'pace' => ['per_minute' => 50]])->assertOk());

    expect($quiet['warnings'])->toBe([])
        ->and($started['warnings'])->toHaveCount(1)
        ->and($started['warnings'][0])->toStartWith('The campaigns driver "fake" reads nothing back from its service')
        ->and($started['warnings'][0])->toContain('no bounce, spam report or unsubscribe made at the service reaches the package')
        ->and($resumed['warnings'])->toBe($started['warnings'])
        // A speed-up changes nothing the driver reads back.
        ->and($sped['warnings'])->toBe([])
        ->and($paused)->not->toHaveKeys(['warnings', 'readings']);
});

it('answers a start, a resume and a speed-up with Gmail\'s spam rate and the provider\'s usage, and what they warn of', function (string $driver): void {
    useDatabase($driver);
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00', 'UTC'));
    Queue::fake([FreezeCampaignJob::class, SendMessageJob::class]);
    fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0035]]));
    fakeDriver()->used(49_990, 50_000);
    draftFor(5);

    // The freeze is queued, so the approved count is what is still to send:
    // 49,990 and 5 stay inside the plan.
    $started = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 5])->assertOk());

    expect($started['campaign']['status'])->toBe('starting')
        ->and($started['readings']['gmail'])->toMatchArray(['read' => true, 'spam_rate' => 0.35, 'spam_rate_date' => '2026-11-21'])
        ->and($started['readings']['usage'])->toMatchArray(['read' => true, 'period' => 'month', 'sent' => 49_990, 'allowance' => 50_000, 'left' => 10, 'past_allowance' => 'billed'])
        ->and($started['warnings'])->toBe([$started['readings']['gmail']['warning']])
        ->and($started['warnings'][0])->toContain('stop line');

    Cache::flush();
    fakeDriver()->used(49_999, 50_000);
    Queue::pushed(FreezeCampaignJob::class)->first()->handle(app(FreezeCampaignAction::class));
    agent()->tool(PauseCampaignTool::class, ['key' => 'march'])->assertOk();
    CampaignMessage::query()->whereKey(CampaignMessage::query()->min('id'))->update(['status' => MessageStatus::Sent, 'claimed_at' => now(), 'sent_at' => now()]);

    // A resume counts its emails still to send (four of five) with the app's
    // other open campaigns' (two of another sending one; a draft's none).
    $resumed = structuredContent(agent()->tool(ResumeCampaignTool::class, ['key' => 'march'])->assertOk());
    agent()->tool(PauseCampaignTool::class, ['key' => 'march'])->assertOk();
    sendingCampaign(2, key: 'april', list: 'april-readers');
    Queue::pushed(FreezeCampaignJob::class)->last()->handle(app(FreezeCampaignAction::class));
    draftFor(3, 'may');
    $withOthers = structuredContent(agent()->tool(ResumeCampaignTool::class, ['key' => 'march'])->assertOk());

    expect($resumed['campaign']['status'])->toBe('sending')
        ->and($resumed['warnings'])->toHaveCount(2)
        ->and($resumed['warnings'][1])->toBe('The account has sent 49999 of the 50000 emails its plan includes this month: of this campaign\'s 4 still to send, 3 pass it, which the provider bills as overage if they all go this month.')
        ->and(Campaign::forKey('april')->status)->toBe(CampaignStatus::Sending)
        ->and($withOthers['warnings'][1])->toBe('The account has sent 49999 of the 50000 emails its plan includes this month: of this campaign\'s 4 and the app\'s other open campaigns\' 2 still to send, 5 pass it, which the provider bills as overage if they all go this month.');

    // A quota: the lesser of the emails still to send and what the pace lets
    // the campaign send in the next 24 hours (its first pace day's rest, one
    // claimed, and the whole next day), against what the last 24 left.
    Cache::flush();
    fakeDriver()->used(198, 200, Usage::DAY, enforced: true, perSecond: 14.0);
    $fits = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['daily_cap' => 1]])->assertOk());
    $capped = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['daily_cap' => 2]])->assertOk());
    $uncapped = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['daily_cap' => null]])->assertOk());

    expect($fits['readings']['usage'])->toMatchArray(['period' => 'last_24_hours', 'sent' => 198, 'allowance' => 200, 'left' => 2, 'past_allowance' => 'held_back', 'per_second' => 14.0])
        ->and($fits['warnings'])->toHaveCount(1)
        ->and($capped['warnings'][1])->toBe('The account may send 2 more of its 200 in 24 hours (it sent 198 in the last 24), while its pace lets it send 3 in the next 24 hours: what passes the quota the provider holds back, so its sending slows.')
        ->and($uncapped['warnings'][1])->toBe('The account may send 2 more of its 200 in 24 hours (it sent 198 in the last 24), while its 4 emails still to send could all go in the next 24 hours: what passes the quota the provider holds back, so its sending slows.');
})->with('databases');

it('words a plan already passed, and warns of a quota only by what the pace can send in a day', function (): void {
    $plan = ['period' => Usage::MONTH, 'sent' => 60_000, 'allowance' => 50_000, 'past_allowance' => 'billed'];
    $quota = ['period' => Usage::DAY, 'sent' => 0, 'allowance' => 50_000, 'past_allowance' => 'held_back'];

    expect(ProviderUsage::warning($plan, 4, null))->toBe('The account has sent 60000 this month, already 10000 past the 50000 its plan includes: this campaign\'s 4 still to send all pass it, which the provider bills as overage if they all go this month.')
        ->and(ProviderUsage::warning($plan, 4, null, 30))->toContain('this campaign\'s 4 and the app\'s other open campaigns\' 30 still to send all pass it')
        ->and(ProviderUsage::warning([...$plan, 'sent' => 10_000], 25_000, null, 30_000))->toContain('of this campaign\'s 25000 and the app\'s other open campaigns\' 30000 still to send, 15000 pass it')
        ->and(ProviderUsage::warning([...$plan, 'past_allowance' => 'held_back'], 4, null))->toEndWith('which the provider holds back until next month.')
        // 60,000 to send at a pace of 100 the first day fits a quota of 50,000.
        ->and(ProviderUsage::warning($quota, 60_000, 100))->toBeNull()
        ->and(ProviderUsage::warning($quota, 60_000, 50_001))->toContain('its pace lets it send 50001 in the next 24 hours')
        ->and(ProviderUsage::warning($quota, 60_000, null))->toContain('its 60000 emails still to send could all go')
        // Other campaigns don't count against a day's quota.
        ->and(ProviderUsage::warning($quota, 100, 100, 1_000_000))->toBeNull()
        ->and(ProviderUsage::warning($plan, 0, null))->toBeNull()
        ->and(ProviderUsage::warning($plan, null, null))->toBeNull()
        ->and(ProviderUsage::warning([...$plan, 'allowance' => null], 4, null))->toBeNull()
        // A sum past the largest integer stays an integer.
        ->and(ProviderUsage::warning($plan, PHP_INT_MAX, null, 5))->toContain('this campaign\'s '.PHP_INT_MAX.' and the app\'s other open campaigns\' 5 still to send all pass it');
});

it('counts what the current pace day has left with the whole next day toward a quota', function (): void {
    $this->travelTo(Carbon::parse('2026-11-23 08:00:00', 'UTC'));
    Queue::fake([SendMessageJob::class]);
    $campaign = sendingCampaign(8, ['first_day' => 3, 'daily_growth' => 0, 'per_minute' => 100], key: 'march');
    CampaignMessage::query()->whereKey(CampaignMessage::query()->where('campaign_id', $campaign->id)->min('id'))->update(['status' => MessageStatus::Sent, 'claimed_at' => now(), 'sent_at' => now()]);

    // Late in its first pace day: 2 of its 3 left today, 3 tomorrow, both
    // within the next 24 hours; the next day's 3 alone would fit the 4 left.
    $this->travel(22)->hours();
    fakeDriver()->used(196, 200, Usage::DAY, enforced: true);

    $answer = structuredContent(agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 100]])->assertOk());

    expect($answer['warnings'])->toBe(['The account may send 4 more of its 200 in 24 hours (it sent 196 in the last 24), while its pace lets it send 5 in the next 24 hours: what passes the quota the provider holds back, so its sending slows.']);

    // Held to per_minute through a whole day; before it starts, its first
    // pace day only.
    $nextDay = fn (Campaign $campaign): int => (fn (): int => static::nextDay($campaign))->call(app(SpeedUpCampaignTool::class));
    agent()->tool(SpeedUpCampaignTool::class, ['key' => 'march', 'pace' => ['first_day' => 2000]])->assertOk();
    $fast = $nextDay(Campaign::forKey('march'));
    agent()->tool(SlowDownCampaignTool::class, ['key' => 'march', 'pace' => ['per_minute' => 1]])->assertOk();

    expect($fast)->toBe(1999 + 2000)
        ->and($nextDay(Campaign::forKey('march')))->toBe(1440)
        ->and($nextDay(draftFor(1, 'may')->forceFill(['pace' => ['first_day' => 7]])))->toBe(7);
});

it('answers a start whose readings can\'t be read, since the start is already made', function (): void {
    Queue::fake([FreezeCampaignJob::class, SendMessageJob::class]);
    Exceptions::fake();
    draftFor(1);
    draftFor(1, 'may');

    // A driver an app registered whose usage fails in its own way.
    app(DriverManager::class)->swap('fake', new class extends FakeDriver
    {
        public function usage(): Usage
        {
            throw new RuntimeException('The usage broke for reader@example.org.');
        }
    });

    $started = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 1])->assertOk()->assertHasNoErrors());

    expect($started['campaign']['status'])->toBe('starting')
        ->and($started['readings']['usage'])->toMatchArray(['read' => false, 'sent' => null])
        ->and($started['readings']['usage']['note'])->toBe('The provider\'s usage couldn\'t be read (RuntimeException). It is read again after 5 minutes.')
        ->and(json_encode($started))->not->toContain('reader@example.org');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The usage broke for reader@example.org.');

    // A driver that can't even be built.
    app(Campaigns::class)->extend('broken', fn (): never => throw new LogicException('The creator broke.'));
    config(['campaigns.driver' => 'broken']);

    $broken = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'may', 'recipients' => 1])->assertOk()->assertHasNoErrors());

    expect(Campaign::forKey('may')->status)->toBe(CampaignStatus::Starting)
        ->and($broken['readings'])->toBeNull()
        ->and($broken['warnings'])->toBe(['The readings couldn\'t be read (LogicException), so nothing here says how Gmail\'s spam rate, the provider\'s usage or the driver stand: read get_overview before going on.']);

    Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage() === 'The creator broke.');
});

it('stays under the answer limit with the readings at their longest', function (string $character): void {
    Queue::fake([FreezeCampaignJob::class, SendMessageJob::class]);
    draftFor(1);
    Campaign::forKey('march')->forceFill(['name' => mb_substr(str_repeat($character, 255), 0, 255)])->save();

    $days = [];

    foreach (range(1, GmailReading::DAYS) as $day) {
        $days[now()->utc()->subDays($day)->toDateString()] = array_fill_keys(array_keys(GmailReading::METRICS), 0.1234567);
    }

    $checks = [
        'domainId' => str_repeat('d', 253),
        'rowData' => array_map(fn (int $n): array => ['requirement' => str_pad("R{$n}_", 40, 'X'), 'status' => ['status' => str_repeat('N', 40)]], range(1, GmailReading::REQUIREMENTS + 5)),
        'oneClickUnsubscribeVerdict' => ['status' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
        'honorUnsubscribeVerdict' => ['status' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
        'deliverabilityStatusVerdict' => ['state' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
    ];

    fakeGoogle(postmasterStats($days), ['complianceData' => $checks, 'subdomainComplianceData' => $checks]);
    app(DriverManager::class)->swap('fake', (new FakeDriver)->used(PHP_INT_MAX, PHP_INT_MAX - 1, Usage::DAY, enforced: true, perSecond: 123456.789012345, notes: [str_repeat($character, 5000)]));

    $answer = structuredContent(agent()->tool(StartCampaignTool::class, ['key' => 'march', 'recipients' => 1])->assertOk()->assertHasNoErrors());

    expect(mb_strlen((string) json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))->toBeLessThan(CampaignsTool::RESULT_LIMIT)
        ->and($answer['readings']['gmail']['compliance']['subdomain']['requirements'])->toHaveCount(GmailReading::REQUIREMENTS)
        ->and($answer['warnings'])->toHaveCount(2);
})->with('costly characters');
