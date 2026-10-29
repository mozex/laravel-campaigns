<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mozex\Campaigns\Actions\ChangePaceAction;
use Mozex\Campaigns\Actions\PauseCampaignAction;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\ResumeCampaignAction;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Events\CampaignCompletedEvent;
use Mozex\Campaigns\Events\CampaignQueueStalledEvent;
use Mozex\Campaigns\Events\CampaignStartedEvent;
use Mozex\Campaigns\Jobs\FreezeCampaignJob;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\OutgoingMessage;
use Mozex\Campaigns\Sending\PumpResult;

beforeEach(function (): void {
    // A UTC instant, since a wall time in another timezone read differently
    // on CI's Carbon.
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
});

function pump(): PumpResult
{
    return app(PumpCampaignsAction::class)->execute();
}

/**
 * The addresses the fake provider was given, in order.
 *
 * @return list<string>
 */
function sentTo(): array
{
    return array_map(fn (OutgoingMessage $message): string => $message->to, fakeProvider()->sent());
}

/**
 * Runs the send jobs the faked queue holds, and empties it.
 */
function runSendJobs(): void
{
    $jobs = Queue::pushed(SendMessageJob::class);
    Queue::fake([SendMessageJob::class]);

    foreach ($jobs as $job) {
        app()->call([$job, 'handle']);
    }
}

describe('the pump', function (): void {
    it('does nothing while sending is switched off', function (): void {
        $campaign = sendingCampaign(3);
        $scheduled = Campaign::factory()->create(['status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->subMinute()]);
        config()->set('campaigns.enabled', false);

        $this->artisan('campaigns:pump')->expectsOutputToContain('Sending is switched off')->assertSuccessful();

        expect(CampaignMessage::query()->where('status', MessageStatus::Pending)->count())->toBe(3)
            ->and($scheduled->fresh()->status)->toBe(CampaignStatus::Scheduled)
            ->and(sentTo())->toBe([]);

        config()->set('campaigns.enabled', true);

        $this->artisan('campaigns:pump')->expectsOutputToContain('queued 3 messages for 1 campaigns')->assertSuccessful();

        expect(sentTo())->toBe(['news-1@example.org', 'news-2@example.org', 'news-3@example.org'])
            ->and($campaign->messages()->where('status', MessageStatus::Sent)->count())->toBe(3);
    });

    it('hands each pending message to one run only, when two runs read the same ones', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(4);
        Queue::fake([SendMessageJob::class]);
        $pump = app(PumpCampaignsAction::class);
        [$first, $second] = [(string) Str::uuid(), (string) Str::uuid()];

        // Both runs read the next three before either claims them.
        $read = $pump->pendingIds($campaign, 3);
        $readAgain = $pump->pendingIds($campaign, 3);

        $claimed = $pump->claim($campaign, $read, $first, now());
        $claimedAgain = $pump->claim($campaign, $readAgain, $second, now());

        expect($readAgain)->toBe($read)
            ->and($claimed)->toBe($read)
            ->and($claimedAgain)->toBe([])
            ->and(CampaignMessage::query()->whereIn('id', $read)->pluck('claim_id')->unique()->all())->toBe([$first])
            ->and($pump->pendingIds($campaign, 3))->toHaveCount(1);

        Queue::assertPushed(SendMessageJob::class, 3);
        Queue::assertPushed(SendMessageJob::class, fn (SendMessageJob $job): bool => $job->claimId === $first);
        Queue::assertNotPushed(SendMessageJob::class, fn (SendMessageJob $job): bool => $job->claimId === $second);
    })->with('databases');

    it('sends a paused campaign nothing, and resumes where it stopped', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(5, ['per_minute' => 2]);
        Queue::fake([SendMessageJob::class]);

        expect(pump()->claimed)->toBe([$campaign->id => 2]);

        // Paused with two messages already handed to the queue: their jobs
        // put them back, and the pump hands it nothing more.
        app(PauseCampaignAction::class)->execute($campaign, 'A look');
        runSendJobs();
        $this->travel(1)->minutes();

        expect(pump()->claimed)->toBe([])
            ->and(sentTo())->toBe([])
            ->and($campaign->messages()->where('status', MessageStatus::Pending)->count())->toBe(5)
            ->and($campaign->messages()->whereNotNull('claim_id')->count())->toBe(0);

        app(ResumeCampaignAction::class)->execute($campaign);

        foreach (range(1, 3) as $minute) {
            pump();
            runSendJobs();
            $this->travel(1)->minutes();
        }

        expect(sentTo())->toBe(['news-1@example.org', 'news-2@example.org', 'news-3@example.org', 'news-4@example.org', 'news-5@example.org'])
            ->and(pump()->completed)->toBe([$campaign->id])
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
    })->with('databases');

    it('sends each day\'s volume, counted from when its pace started, and starts over after a long pause', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(20, ['first_day' => 3, 'daily_growth' => 100, 'per_minute' => 100]);

        expect(pump()->claimed)->toBe([$campaign->id => 3]);

        $this->travel(23)->hours();

        expect(pump()->claimed)->toBe([]);

        // The second day sends twice the first.
        $this->travel(1)->hours();

        expect(pump()->claimed)->toBe([$campaign->id => 6]);

        $this->travel(1)->minutes();

        expect(pump()->claimed)->toBe([]);

        // A pause longer than a day starts the pace over from its first day.
        app(PauseCampaignAction::class)->execute($campaign);
        $this->travel(25)->hours();
        app(ResumeCampaignAction::class)->execute($campaign);

        expect(pump()->claimed)->toBe([$campaign->id => 3])
            ->and(sentTo())->toHaveCount(12);
    })->with('databases');

    it('shares a sending domain\'s daily cap across its campaigns and its double opt-in emails', function (string $driver): void {
        useDatabase($driver);
        $news = sendingCampaign(6, ['per_minute' => 100]);
        $promo = sendingCampaign(4, ['per_minute' => 100], key: 'promo', list: 'promo-readers');
        Subscriber::factory()->create(['confirmation_sent_at' => now()->subHour()]);

        // A campaign that finished today still counts; another domain's
        // sending counts against its own cap, not this one.
        $finished = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
        CampaignMessage::factory()->count(2)->for($finished)->create(['status' => MessageStatus::Delivered, 'claimed_at' => now()->subHours(2)]);
        $other = Campaign::factory()->sending()->create(['from_email' => 'team@other.example.net']);
        CampaignMessage::factory()->count(3)->for($other)->create(['status' => MessageStatus::Sent, 'claimed_at' => now()]);
        config()->set('campaigns.domain_daily_caps', ['news.example.com' => 7, 'other.example.net' => 1]);

        // Seven a day: one already gone as a confirmation, two in the
        // finished campaign.
        expect(pump()->claimed)->toBe([$news->id => 4]);

        $this->travel(1)->hours();

        expect(pump()->claimed)->toBe([]);

        // With no cap for the domain, its sending campaigns' smallest daily
        // cap is the domain's.
        $this->travel(24)->hours();
        config()->set('campaigns.domain_daily_caps', []);
        app(ChangePaceAction::class)->execute($promo, ['daily_cap' => 2], faster: false);

        expect(pump()->claimed)->toBe([$news->id => 2])
            ->and(sentTo())->toHaveCount(6);
    })->with('databases');

    it('sends only within its sending hours, read in its own timezone', function (string $driver): void {
        useDatabase($driver);
        // 22:00 to 02:00 in Tokyo is 13:00 to 17:00 UTC.
        $campaign = sendingCampaign(5, ['per_minute' => 1, 'sending_hours' => ['22:00', '02:00'], 'timezone' => 'Asia/Tokyo']);
        $claims = [];

        foreach (['12:59', '13:00', '15:30', '16:59', '17:00'] as $time) {
            $this->travelTo(Carbon::parse("2026-03-02 {$time}:00"));
            $claims[$time] = pump()->claimed[$campaign->id] ?? 0;
        }

        expect($claims)->toBe(['12:59' => 0, '13:00' => 1, '15:30' => 1, '16:59' => 1, '17:00' => 0]);
    })->with('databases');

    it('claims nothing while queued messages wait, and says so', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(3, ['per_minute' => 1]);
        Queue::fake([SendMessageJob::class]);
        $claimedAt = now();

        pump();
        $this->travel(4)->minutes();

        expect(pump()->claimed)->toBe([$campaign->id => 1]);

        $this->travel(2)->minutes();
        Event::fake([CampaignQueueStalledEvent::class]);
        $result = pump();

        expect($result->claimed)->toBe([])
            ->and($result->stalled)->toBe([$campaign->id]);

        Event::assertDispatched(CampaignQueueStalledEvent::class, fn (CampaignQueueStalledEvent $event): bool => $event->campaign->is($campaign)
            && $event->stalledMessages === 1
            && $event->oldestClaimedAt->equalTo($claimedAt));

        // Once the worker takes them, sending goes on.
        runSendJobs();

        expect(pump()->claimed)->toBe([$campaign->id => 1])
            ->and(sentTo())->toHaveCount(2);
    })->with('databases');

    it('starts a scheduled campaign when its time comes', function (string $driver): void {
        useDatabase($driver);
        $campaign = sendingCampaign(2, at: now()->addHour());
        Event::fake([CampaignStartedEvent::class]);

        expect(pump()->started)->toBe([])
            ->and($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled);

        $this->travel(1)->hours();
        $result = pump();

        expect($result->started)->toBe([$campaign->id])
            ->and($result->claimed)->toBe([$campaign->id => 2])
            ->and($campaign->fresh())
            ->status->toBe(CampaignStatus::Sending)
            ->started_at->toEqual(now())
            ->pace_started_at->toEqual(now());

        Event::assertDispatchedTimes(CampaignStartedEvent::class, 1);
    })->with('databases');

    it('hands back a start whose freeze job was lost, and only that one', function (): void {
        sendFromExample();
        $lost = Campaign::factory()->create(['status' => CampaignStatus::Starting, 'start_run' => (string) Str::uuid()]);
        $waiting = Campaign::factory()->create(['status' => CampaignStatus::Starting, 'start_run' => (string) Str::uuid()]);
        $limit = FreezeCampaignJob::TIMEOUT + PumpCampaignsAction::STARTING_GRACE;
        Campaign::query()->whereKey($lost->id)->update(['updated_at' => now()->subSeconds($limit + 1)]);
        Campaign::query()->whereKey($waiting->id)->update(['updated_at' => now()->subSeconds($limit - 60)]);

        expect(pump()->handedBack)->toBe([$lost->id])
            ->and($lost->fresh())
            ->status->toBe(CampaignStatus::Draft)
            ->start_error->toContain('Start it again')
            ->and($waiting->fresh()->status)->toBe(CampaignStatus::Starting);
    });

    it('completes a campaign once no message waits or is on its way', function (): void {
        $campaign = sendingCampaign(3);
        $paused = Campaign::factory()->create(['status' => CampaignStatus::Paused]);
        // Another sending campaign, so the pump reads several and a listener
        // must still get one that may load its relations.
        CampaignMessage::factory()->for(Campaign::factory()->sending())->create();
        $completions = [];
        Event::listen(CampaignCompletedEvent::class, function (CampaignCompletedEvent $event) use (&$completions): void {
            $completions[] = $event->campaign->frozenRevision?->id;
        });
        Queue::fake([SendMessageJob::class]);

        // Two of its three sent, the third still queued.
        pump();
        $jobs = Queue::pushed(SendMessageJob::class)
            ->filter(fn (SendMessageJob $job): bool => CampaignMessage::query()->find($job->messageId)?->campaign_id === $campaign->id)
            ->values();
        app()->call([$jobs[0], 'handle']);
        app()->call([$jobs[1], 'handle']);
        [$first, $second, $third] = $jobs->map(fn (SendMessageJob $job): int => $job->messageId)->all();

        expect(pump()->completed)->toBe([]);

        CampaignMessage::query()->whereKey($third)->update(['status' => MessageStatus::Sending]);

        expect(pump()->completed)->toBe([]);

        CampaignMessage::query()->whereKey($third)->update(['status' => MessageStatus::Unknown]);

        expect(pump()->completed)->toBe([$campaign->id])
            ->and(pump()->completed)->toBe([])
            ->and($campaign->fresh())
            ->status->toBe(CampaignStatus::Completed)
            ->completed_at->toEqual(now())
            ->and($completions)->toBe([$campaign->frozen_revision_id])
            ->and($paused->fresh()->status)->toBe(CampaignStatus::Paused)
            ->and(CampaignMessage::query()->whereKey([$first, $second])->pluck('status')->unique()->all())->toBe([MessageStatus::Sent]);
    });
});
