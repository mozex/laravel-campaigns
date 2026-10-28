<?php

declare(strict_types=1);

use Mozex\Campaigns\Actions\ForgetSubscriberAction;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Tests\Fixtures\Member;

it('deletes an address nobody blocked or opted out, keeping its records and the campaigns\' numbers', function (string $driver): void {
    useDatabase($driver);

    $subscriber = Subscriber::factory()->create();
    Subscription::factory()->for($subscriber)->create();
    ConsentRecord::factory()->for($subscriber)->create(['ip_address' => '203.0.113.9', 'user_agent' => 'Browser/1.0']);
    $pending = CampaignMessage::factory()->for($subscriber)->create();
    $sent = CampaignMessage::factory()->for($subscriber)->create(['status' => MessageStatus::Delivered]);
    $event = ProviderEvent::factory()->for($sent, 'message')->create(['payload' => ['recipient' => $subscriber->email]]);

    app(ForgetSubscriberAction::class)->execute($subscriber);

    expect(Subscriber::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::Subscribe)
        ->subscriber_id->toBeNull()
        ->ip_address->toBeNull()
        ->user_agent->toBeNull()
        ->and($pending->fresh())
        ->status->toBe(MessageStatus::Skipped)
        ->email->toBeNull()
        ->subscriber_id->toBeNull()
        ->and($sent->fresh())
        ->status->toBe(MessageStatus::Delivered)
        ->email->toBeNull()
        ->and($event->fresh()?->payload)->toBeNull();
})->with('databases');

it('keeps a blocked or opted-out address, stripped to that state', function (string $state): void {
    $member = Member::query()->create(['email' => 'jane@example.com', 'name' => 'Jane Doe', 'locale' => 'en']);
    $subscriber = Subscriber::findFor($member);
    $subscriber?->forceFill([$state => now(), 'custom_fields' => ['plan' => 'pro'], 'last_active_at' => now()])->save();

    app(ForgetSubscriberAction::class)->execute($subscriber);

    expect(Subscriber::query()->sole())
        ->email->toBe('jane@example.com')
        ->{$state}->not->toBeNull()
        ->subscribable_type->toBeNull()
        ->name->toBeNull()
        ->first_name->toBeNull()
        ->locale->toBeNull()
        ->custom_fields->toBeNull()
        ->last_active_at->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
})->with(['blocked_at', 'opted_out_at']);
