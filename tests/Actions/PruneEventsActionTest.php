<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\FreezeReportAction;
use Mozex\Campaigns\Actions\PruneEventsAction;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\TestSend;
use Mozex\Campaigns\Models\Upload;
use Mozex\Campaigns\Models\WebhookToken;
use Mozex\Campaigns\Reports\CampaignReport;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-03-02 12:00:00'));
});

/**
 * A campaign in the given status with one email, and a machine open of it
 * at each of the given ages in days.
 *
 * @param  list<int>  $ages
 */
function campaignWithEvents(CampaignStatus $status, array $ages): Campaign
{
    $campaign = Campaign::factory()->create(['status' => $status, 'started_at' => now()->subYear()]);
    $message = CampaignMessage::factory()->for($campaign)->create();

    foreach ($ages as $age) {
        ProviderEvent::factory()->for($message, 'message')->create(['type' => FeedbackType::Opened, 'is_bot' => true, 'occurred_at' => now()->subDays($age)]);
    }

    return $campaign;
}

it('freezes a finished campaign\'s report, then deletes the events past the retention; a running or paused campaign keeps its own', function (string $driver): void {
    useDatabase($driver);
    Storage::fake('local');

    $completed = campaignWithEvents(CampaignStatus::Completed, [181, 10]);
    $canceled = campaignWithEvents(CampaignStatus::Canceled, [200]);
    $sending = campaignWithEvents(CampaignStatus::Sending, [200]);
    $paused = campaignWithEvents(CampaignStatus::Paused, [200]);
    $recent = campaignWithEvents(CampaignStatus::Completed, [179]);
    $testSend = TestSend::factory()->create();
    ProviderEvent::factory()->create(['message_id' => null, 'test_send_id' => $testSend->id, 'occurred_at' => now()->subDays(181)]);
    ProviderEvent::factory()->create(['message_id' => null, 'test_send_id' => $testSend->id, 'occurred_at' => now()->subDays(1)]);
    WebhookToken::factory()->create(['created_at' => now()->subHours(WebhookToken::KEEP_HOURS)->subMinute()]);
    $kept = WebhookToken::factory()->create(['created_at' => now()->subHours(WebhookToken::KEEP_HOURS)->addMinute()]);
    Upload::factory()->create(['expires_at' => now()->subMinute()]);

    $this->artisan('campaigns:prune')
        ->expectsOutputToContain('Froze 2 campaign reports, deleted 3 provider events, 1 webhook tokens and 1 expired uploads.')
        ->assertSuccessful();

    $remaining = fn (Campaign $campaign): int => ProviderEvent::query()->whereHas('message', fn (Builder $query): Builder => $query->where('campaign_id', $campaign->id))->count();

    expect($remaining($completed))->toBe(1)
        ->and($remaining($canceled))->toBe(0)
        ->and($remaining($sending))->toBe(1)
        ->and($remaining($paused))->toBe(1)
        ->and($remaining($recent))->toBe(1)
        ->and(ProviderEvent::query()->where('test_send_id', $testSend->id)->count())->toBe(1)
        ->and(WebhookToken::query()->pluck('id')->all())->toBe([$kept->id])
        // The frozen report still counts what was deleted.
        ->and(CampaignReport::for($completed->fresh())['opens']['machine'])->toBe(2)
        ->and($recent->fresh()->report)->toBeNull()
        ->and($sending->fresh()->report)->toBeNull();

    expect(app(PruneEventsAction::class)->execute())->toBe(['frozen' => 0, 'events' => 0, 'tokens' => 0]);
})->with('databases');

it('keeps the events of a campaign whose report couldn\'t be frozen, until a later run freezes it', function (): void {
    Exceptions::fake();
    $campaign = campaignWithEvents(CampaignStatus::Completed, [200]);

    app()->instance(FreezeReportAction::class, new class extends FreezeReportAction
    {
        public function execute(Campaign $campaign): bool
        {
            throw new RuntimeException('The report broke.');
        }
    });

    expect(app(PruneEventsAction::class)->execute())->toBe(['frozen' => 0, 'events' => 0, 'tokens' => 0])
        ->and(ProviderEvent::query()->count())->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The report broke.');

    app()->forgetInstance(FreezeReportAction::class);

    expect(app(PruneEventsAction::class)->execute())->toBe(['frozen' => 1, 'events' => 1, 'tokens' => 0])
        ->and($campaign->fresh()->report)->not->toBeNull();
});

it('keeps events for as many days as the config says', function (): void {
    config()->set('campaigns.event_retention_days', 30);
    campaignWithEvents(CampaignStatus::Completed, [31, 29]);

    expect(app(PruneEventsAction::class)->execute()['events'])->toBe(1)
        ->and(ProviderEvent::query()->count())->toBe(1);
});
