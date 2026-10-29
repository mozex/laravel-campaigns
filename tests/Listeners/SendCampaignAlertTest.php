<?php

declare(strict_types=1);

use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Mozex\Campaigns\Actions\PauseCampaignAction;
use Mozex\Campaigns\Events\CampaignFirstComplaintEvent;
use Mozex\Campaigns\Events\CampaignQueueStalledEvent;
use Mozex\Campaigns\Listeners\SendCampaignAlert;
use Mozex\Campaigns\Mail\CampaignAlertMail;
use Mozex\Campaigns\Models\Campaign;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
    config()->set('campaigns.alert_address', 'alerts@example.com');
    config()->set('campaigns.name', 'Example');
    Mail::fake();
});

it('mails the alert address when a campaign pauses itself, never for a person\'s pause', function (): void {
    $campaign = Campaign::factory()->sending()->create(['key' => 'spring', 'name' => 'Spring sale']);

    app(PauseCampaignAction::class)->execute($campaign, 'Looking at the copy again.');
    $campaign->forceFill(['status' => 'sending'])->save();
    app(PauseCampaignAction::class)->execute($campaign, 'The stop-loss paused it: 10 of 100 emails bounced hard.', automatic: true);

    Mail::assertSentCount(1);
    Mail::assertSent(CampaignAlertMail::class, fn (CampaignAlertMail $mail): bool => $mail->hasTo('alerts@example.com')
        && $mail->heading === 'Example: the campaign "Spring sale" paused itself'
        && $mail->lines === [
            'The campaign "Spring sale" (spring) is paused, and nothing more goes out until it is resumed. Why:',
            'The stop-loss paused it: 10 of 100 emails bounced hard.',
            'Read its report and find the cause before resuming it.',
        ]);

    $text = (new CampaignAlertMail('Heading', ["It's \"quoted\" & plain"]))->render();

    expect($text)->toContain("It's \"quoted\" & plain")->not->toContain('&#039;');
});

it('mails a first spam report with the limit that follows it', function (): void {
    $campaign = Campaign::factory()->sending()->create(['key' => 'spring', 'name' => 'Spring sale', 'thresholds' => ['complaints' => 3]]);

    CampaignFirstComplaintEvent::dispatch($campaign);

    Mail::assertSent(CampaignAlertMail::class, fn (CampaignAlertMail $mail): bool => $mail->heading === 'Example: the campaign "Spring sale" got its first spam report'
        && $mail->lines === ['Someone marked an email of the campaign "Spring sale" (spring) as spam. It keeps sending: it pauses itself at 3 spam reports before 2,500 emails, and above 0.08% after that.']);
});

it('mails a stalled queue at most once every six hours, whichever campaign', function (): void {
    $first = Campaign::factory()->sending()->create(['key' => 'first', 'name' => 'First']);
    $second = Campaign::factory()->sending()->create(['key' => 'second', 'name' => 'Second']);

    CampaignQueueStalledEvent::dispatch($first, 1200, now()->subMinutes(7));
    CampaignQueueStalledEvent::dispatch($second, 3, now()->subMinutes(6));

    Mail::assertSentCount(1);
    Mail::assertSent(CampaignAlertMail::class, fn (CampaignAlertMail $mail): bool => $mail->heading === 'Example: campaign emails are waiting on the queue'
        && $mail->lines === [
            '1,200 emails of the campaign "First" (first) have waited on the "campaigns" queue since 2026-03-02 11:53 UTC, and it sends nothing more until they move.',
            'Check that a worker runs that queue. This notice comes at most once every 6 hours.',
        ]);

    $this->travel(6)->hours();
    CampaignQueueStalledEvent::dispatch($second, 3, now()->subMinutes(6));

    Mail::assertSentCount(2);
});

it('tells nobody without an alert address, and holds no stall mark meanwhile', function (): void {
    config()->set('campaigns.alert_address', ' ');
    $campaign = Campaign::factory()->sending()->create();

    app(PauseCampaignAction::class)->execute($campaign, 'Too many bounces.', automatic: true);
    CampaignFirstComplaintEvent::dispatch($campaign);
    CampaignQueueStalledEvent::dispatch($campaign, 3, now()->subMinutes(6));

    Mail::assertNothingSent();

    config()->set('campaigns.alert_address', 'alerts@example.com');
    CampaignQueueStalledEvent::dispatch($campaign, 3, now()->subMinutes(6));

    Mail::assertSentCount(1);
});

it('sends through the app\'s own mailer, a failure reported, the pause kept and no stall held back', function (): void {
    Exceptions::fake();
    config()->set('mail.default', 'nowhere');
    config()->set('campaigns.mail.mailer', 'array');
    Mail::swap(new MailManager(app()));

    $campaign = Campaign::factory()->sending()->create();

    app(PauseCampaignAction::class)->execute($campaign, 'Too many bounces.', automatic: true);
    CampaignQueueStalledEvent::dispatch($campaign, 3, now()->subMinutes(6));

    Exceptions::assertReported(InvalidArgumentException::class);

    expect($campaign->fresh()->status->value)->toBe('paused')
        ->and(Cache::has(SendCampaignAlert::STALLED_MARK))->toBeFalse();
});
