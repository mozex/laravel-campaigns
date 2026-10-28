<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\FeedbackType;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\ProviderEvent;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Models\TestSend;

it('stores the longest address and user agent the columns allow', function (string $driver): void {
    useDatabase($driver);

    $email = str_repeat('a', 64).'@'.str_repeat('b', 185).'.com';
    $subscriber = Subscriber::factory()->create(['email' => $email]);
    ConsentRecord::factory()->for($subscriber)->create(['user_agent' => str_repeat('x', 400)]);

    expect(strlen($email))->toBe(254)
        ->and(Subscriber::query()->sole()->email)->toBe($email)
        ->and(ConsentRecord::query()->sole()->user_agent)->toHaveLength(400);
})->with('databases');

it('builds the whole model graph and resolves every relation', function (string $driver): void {
    useDatabase($driver);

    $list = CampaignList::factory()->create();
    $subscriber = Subscriber::factory()->blocked(BlockReason::Complaint)->create(['custom_fields' => ['plan' => 'pro']]);
    $subscription = Subscription::factory()->for($list, 'list')->for($subscriber)->create();
    $event = ProviderEvent::factory()->create(['message_id' => CampaignMessage::factory()->for($subscriber)]);
    $testSend = TestSend::factory()->for($event->message->campaign)->create();
    ProviderEvent::factory()->create(['message_id' => null, 'test_send_id' => $testSend->id]);
    ApiToken::factory()->create();

    $subscriber->subscribable()->associate($list)->save();

    $message = CampaignMessage::query()->sole();
    $fresh = Subscriber::query()->sole();

    expect($list->subscribers()->sole()->pivot->status)->toBe(SubscriptionStatus::Subscribed->value)
        ->and($subscription->list->is($list))->toBeTrue()
        ->and($fresh->lists()->sole()->is($list))->toBeTrue()
        ->and($fresh->blocked_reason)->toBe(BlockReason::Complaint)
        ->and($fresh->custom_fields)->toBe(['plan' => 'pro'])
        ->and($fresh->subscribable_type)->toBe('campaigns.list')
        ->and($fresh->subscribable->is($list))->toBeTrue()
        ->and($message->status)->toBe(MessageStatus::Pending)
        ->and($message->email)->toBe($subscriber->email)
        ->and($message->uuid)->toBeString()->toHaveLength(36)
        ->and($message->events()->sole()->is($event))->toBeTrue()
        ->and($event->fresh()->type)->toBe(FeedbackType::Delivered)
        ->and($message->campaign->testSends()->sole()->events()->sole()->testSend->is($testSend))->toBeTrue()
        ->and($message->campaign->uuid)->toBeString()->toHaveLength(36);
})->with('databases');

it('keeps the consent records and messages of a deleted subscriber', function (string $driver): void {
    useDatabase($driver);

    $subscriber = Subscriber::factory()->create();
    Subscription::factory()->for($subscriber)->create();
    ConsentRecord::factory()->for($subscriber)->create(['action' => ConsentAction::OptOut]);
    CampaignMessage::factory()->for($subscriber)->create();

    $subscriber->delete();

    expect(Subscription::query()->count())->toBe(0)
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::OptOut)
        ->subscriber_id->toBeNull()
        ->and(CampaignMessage::query()->sole()->subscriber_id)->toBeNull();
})->with('databases');

it('gives every model a prefixed morph alias', function (): void {
    $models = [
        new ApiToken, new Campaign, new CampaignList, new CampaignMessage, new ConsentRecord,
        new ProviderEvent, new Subscriber, new Subscription, new TestSend,
    ];

    Relation::requireMorphMap();

    try {
        foreach ($models as $model) {
            expect($model->getMorphClass())->toStartWith('campaigns.')
                ->and(Relation::getMorphedModel($model->getMorphClass()))->toBe($model::class);
        }
    } finally {
        Relation::requireMorphMap(false);
    }
});
