<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\CreateCampaignAction;
use Mozex\Campaigns\Actions\FreezeCampaignAction;
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
use Mozex\Campaigns\Reports\ReportSummary;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeSendOnlyDriver;
use Mozex\Campaigns\Sending\Pace;

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

    expect($quiet['warning'])->toBeNull()
        ->and($started['warning'])->toStartWith('The campaigns driver "fake" reads nothing back from its service')
        ->and($started['warning'])->toContain('no bounce, spam report or unsubscribe made at the service reaches the package')
        ->and($resumed['warning'])->toBe($started['warning'])
        ->and($paused)->not->toHaveKey('warning');
});
