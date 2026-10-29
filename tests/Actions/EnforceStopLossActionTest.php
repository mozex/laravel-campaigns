<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Mozex\Campaigns\Actions\EnforceStopLossAction;
use Mozex\Campaigns\Actions\ResumeCampaignAction;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\DeliveryFailure;
use Mozex\Campaigns\Events\CampaignFirstComplaintEvent;
use Mozex\Campaigns\Events\CampaignPausedEvent;
use Mozex\Campaigns\Jobs\EnforceStopLossJob;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Sending\AppliedFeedback;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
    config()->set('campaigns.enabled', true);
});

function enforce(Campaign $campaign, ?DeliveryFailure $failed = DeliveryFailure::HardBounce, bool $complained = false, bool $unsubscribed = false): void
{
    app(EnforceStopLossAction::class)->execute(new AppliedFeedback($campaign->id, $failed, $complained, $unsubscribed));
}

it('pauses a campaign past its limit, and a resume gives it and its domain a fresh start', function (): void {
    $paused = [];
    Event::listen(CampaignPausedEvent::class, function (CampaignPausedEvent $event) use (&$paused): void {
        // Found on its own, so a listener may read its relations.
        $paused[] = [$event->campaign->id, $event->reason, $event->automatic, $event->campaign->messages->count()];
    });

    $campaign = Campaign::factory()->sending()->create();
    sends($campaign, 90);
    sends($campaign, 10, hardBounce());

    enforce($campaign);

    $reason = 'The stop-loss paused it: 10 of 100 emails bounced hard, reaching the limit of 10 before 200 emails (early_hard_bounces).';

    // The one campaign on its domain trips the domain too, on the same
    // numbers: already paused, it pauses once, and both start again.
    expect($campaign->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toBe($reason)
        ->limits_reset_at->toEqual(now())
        ->domain_limits_reset_at->toEqual(now())
        ->and($paused)->toBe([[$campaign->id, $reason, true, 100]]);

    $this->travel(10)->minutes();
    app(ResumeCampaignAction::class)->execute($campaign->fresh());
    sends($campaign, 5, at: now());
    sends($campaign, 1, hardBounce(), now());

    enforce($campaign);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

    sends($campaign, 9, hardBounce(), now());

    enforce($campaign);

    expect($campaign->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toContain('10 of 15 emails bounced hard')
        ->and($paused)->toHaveCount(2);
});

it('checks only after a failure, a spam report or an unsubscribe', function (): void {
    $campaign = Campaign::factory()->sending()->create();
    sends($campaign, 10, hardBounce());

    enforce($campaign, failed: null);
    app(EnforceStopLossAction::class)->execute(new AppliedFeedback);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

    enforce($campaign, failed: DeliveryFailure::SoftBounce);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('tells of a first spam report without pausing, once', function (): void {
    Event::fake([CampaignFirstComplaintEvent::class]);

    $campaign = Campaign::factory()->sending()->create();
    sends($campaign, 100);
    sends($campaign, 1, ['complained_at' => now()]);

    enforce($campaign, failed: null, complained: true);
    // A message's first report that isn't the campaign's first, under a
    // limit (the campaign's and its domain's) it doesn't reach.
    sends($campaign, 1, ['complained_at' => now()]);
    config()->set('campaigns.stop_loss.complaints', 3);
    enforce($campaign, failed: null, complained: true);

    Event::assertDispatchedTimes(CampaignFirstComplaintEvent::class, 1);
    Event::assertDispatched(CampaignFirstComplaintEvent::class, fn (CampaignFirstComplaintEvent $event): bool => $event->campaign->is($campaign));

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('tells of a first spam report only while the campaign keeps sending', function (): void {
    Event::fake([CampaignFirstComplaintEvent::class]);

    // Finished: it will never pause on its own limits.
    $finished = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHours(30)]);
    sends($finished, 1, ['complained_at' => now()], now()->subHours(30));
    enforce($finished, failed: null, complained: true);

    // Its domain had a report today from another campaign, so this first
    // one pauses it with the domain.
    $earlier = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHour()]);
    sends($earlier, 1, ['complained_at' => now()]);
    $running = Campaign::factory()->sending()->create();
    sends($running, 1, ['complained_at' => now()]);
    enforce($running, failed: null, complained: true);

    Event::assertNotDispatched(CampaignFirstComplaintEvent::class);

    expect($running->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('pauses at a first spam report its own limit allows no more of, without the warning', function (): void {
    Event::fake([CampaignFirstComplaintEvent::class]);

    $campaign = Campaign::factory()->sending()->create(['thresholds' => ['complaints' => 1]]);
    sends($campaign, 100);
    sends($campaign, 1, ['complained_at' => now()]);

    enforce($campaign, failed: null, complained: true);

    Event::assertNotDispatched(CampaignFirstComplaintEvent::class);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('pauses every running campaign on a domain past its limits, but no other domain\'s', function (): void {
    Event::fake([CampaignPausedEvent::class]);

    // A finished campaign's late bounces push the domain over; its own pause
    // is nothing to do.
    $finished = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHours(2)]);
    sends($finished, 40);
    sends($finished, 8, hardBounce());
    $running = Campaign::factory()->sending()->create();
    sends($running, 40);
    sends($running, 2, hardBounce());
    $scheduled = Campaign::factory()->create(['status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    $elsewhere = Campaign::factory()->sending()->create(['from_email' => 'team@other.example.com']);

    enforce($finished);

    $reason = "The stop-loss paused every campaign on news.example.com: over the domain's last 24 hours, 10 of 90 emails bounced hard, reaching the limit of 10 before 200 emails (early_hard_bounces).";

    expect($running->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toBe($reason)
        ->limits_reset_at->toBeNull()
        ->domain_limits_reset_at->toEqual(now())
        ->and($scheduled->fresh())->status->toBe(CampaignStatus::Paused)->domain_limits_reset_at->toEqual(now())
        ->and($finished->fresh())->status->toBe(CampaignStatus::Completed)->domain_limits_reset_at->toEqual(now())
        ->and($elsewhere->fresh())->status->toBe(CampaignStatus::Sending)->domain_limits_reset_at->toBeNull();

    Event::assertDispatchedTimes(CampaignPausedEvent::class, 2);
    Event::assertDispatched(CampaignPausedEvent::class, fn (CampaignPausedEvent $event): bool => $event->automatic && $event->reason === $reason);
});

it('leaves a domain with nothing running as it is, so the next campaign on it pauses at its first failure', function (): void {
    $finished = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHour()]);
    sends($finished, 10, hardBounce());

    enforce($finished);

    expect($finished->fresh()->domain_limits_reset_at)->toBeNull();

    $next = Campaign::factory()->sending()->create();
    sends($next, 1, ['status' => 'failed', 'failure_reason' => 'soft_bounce']);

    enforce($next, failed: DeliveryFailure::SoftBounce);

    expect($next->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toContain('10 of 11 emails bounced hard');
});

it('measures the domain only after a failure or a spam report, never an unsubscribe', function (): void {
    $finished = Campaign::factory()->create(['status' => CampaignStatus::Completed, 'completed_at' => now()->subHour()]);
    sends($finished, 10, hardBounce());
    $running = Campaign::factory()->sending()->create();
    sends($running, 10);

    enforce($running, failed: null, unsubscribed: true);

    expect($running->fresh()->status)->toBe(CampaignStatus::Sending);

    enforce($running, failed: null, complained: true);

    expect($running->fresh()->status)->toBe(CampaignStatus::Paused);
});

it('never throws, and a listener that fails stops no other pause', function (): void {
    Exceptions::fake();
    Event::listen(CampaignPausedEvent::class, function (CampaignPausedEvent $event): void {
        throw new RuntimeException("listener broke on {$event->campaign->key}");
    });

    $first = Campaign::factory()->sending()->create(['key' => 'first']);
    sends($first, 90);
    sends($first, 10, hardBounce());
    $second = Campaign::factory()->sending()->create(['key' => 'second']);

    enforce($first);

    expect($first->fresh())->status->toBe(CampaignStatus::Paused)->limits_reset_at->toEqual(now())
        ->and($second->fresh())->status->toBe(CampaignStatus::Paused)->domain_limits_reset_at->toEqual(now());

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'listener broke on first');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'listener broke on second');

    // A campaign that is gone, and a check that fails, end quietly.
    app(EnforceStopLossAction::class)->execute(new AppliedFeedback(999_999, DeliveryFailure::HardBounce));
    config()->set('campaigns.stop_loss', []);
    $broken = Campaign::factory()->sending()->create(['key' => 'broken']);
    sends($broken, 1, hardBounce(), now()->addMinute());

    enforce($broken);

    Exceptions::assertReported(ErrorException::class);

    expect($broken->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('measures a campaign\'s unsubscribes from the check an unsubscribe queued', function (): void {
    $campaign = Campaign::factory()->sending()->create();
    sends($campaign, 492);
    sends($campaign, 8, ['unsubscribed_at' => now()]);

    app()->call([new EnforceStopLossJob($campaign->id), 'handle']);

    expect($campaign->fresh())
        ->status->toBe(CampaignStatus::Paused)
        ->pause_reason->toBe('The stop-loss paused it: 8 unsubscribes in 500 emails (1.6%), past the limit of 1.4% (unsubscribe_rate).')
        ->domain_limits_reset_at->toBeNull();
});
