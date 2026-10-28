<?php

declare(strict_types=1);

use Mozex\Campaigns\Actions\BlockAction;
use Mozex\Campaigns\Actions\ConfirmSubscriptionAction;
use Mozex\Campaigns\Actions\DeclineAction;
use Mozex\Campaigns\Actions\OptInAction;
use Mozex\Campaigns\Actions\OptOutAction;
use Mozex\Campaigns\Actions\RemoveFromListAction;
use Mozex\Campaigns\Actions\SubscribeAction;
use Mozex\Campaigns\Actions\UnblockAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;

it('subscribes once, with the list\'s defaults, and records how', function (): void {
    $list = CampaignList::factory()->create(['default_basis' => ConsentBasis::SoftOptIn]);
    $subscriber = Subscriber::factory()->create();
    $context = new ConsentContext('newsletter-form', noticeVersion: 'v2', ipAddress: '203.0.113.9', userAgent: 'Browser/1.0', meta: ['page' => 'home']);

    $first = app(SubscribeAction::class)->execute($subscriber, $list, $context);
    $second = app(SubscribeAction::class)->execute($subscriber, $list, new ConsentContext('import', ConsentBasis::Imported));

    expect($second->is($first))->toBeTrue()
        ->and(Subscription::query()->sole())
        ->status->toBe(SubscriptionStatus::Subscribed)
        ->basis->toBe(ConsentBasis::SoftOptIn)
        ->source->toBe('newsletter-form')
        ->subscribed_at->not->toBeNull()
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::Subscribe)
        ->list_id->toBe($list->id)
        ->basis->toBe(ConsentBasis::SoftOptIn)
        ->source->toBe('newsletter-form')
        ->notice_version->toBe('v2')
        ->ip_address->toBe('203.0.113.9')
        ->user_agent->toBe('Browser/1.0')
        ->meta->toBe(['page' => 'home']);
});

it('clears an opt-out only when the person asked, and never a block', function (): void {
    $list = CampaignList::factory()->create();
    $optedOut = Subscriber::factory()->optedOut()->create();
    $blocked = Subscriber::factory()->optedOut()->blocked()->create();
    $offTheList = Subscriber::factory()->optedOut()->create();
    Subscription::factory()->for($offTheList)->for($list, 'list')->unsubscribed()->create();
    $switch = new ConsentContext('account-switch', accepted: true);

    app(SubscribeAction::class)->execute($optedOut, $list, new ConsentContext('import'));
    $stillOptedOut = $optedOut->fresh()?->opted_out_at;

    app(SubscribeAction::class)->execute($optedOut, $list, $switch);
    app(SubscribeAction::class)->execute($blocked, $list, $switch);
    app(SubscribeAction::class)->execute($offTheList, $list, $switch);

    expect($stillOptedOut)->not->toBeNull()
        ->and($optedOut->fresh()?->opted_out_at)->toBeNull()
        ->and(consentActions($optedOut))->toBe(['subscribe', 'opt_in'])
        ->and(ConsentRecord::query()->where('subscriber_id', $optedOut->id)->where('action', ConsentAction::OptIn)->sole()->basis)->toBe(ConsentBasis::Consent)
        ->and($blocked->fresh())
        ->opted_out_at->toBeNull()
        ->blocked_at->not->toBeNull()
        ->and($offTheList->fresh()?->opted_out_at)->toBeNull();
});

it('waits for a double opt-in, and confirming it clears an earlier opt-out', function (): void {
    $list = CampaignList::factory()->doubleOptIn()->create();
    $subscriber = Subscriber::factory()->optedOut()->create();

    $subscription = app(SubscribeAction::class)->execute($subscriber, $list, new ConsentContext('newsletter-form', accepted: true));
    $statusWhileWaiting = $subscription->status;
    $optOutWhileWaiting = $subscriber->fresh()?->opted_out_at;

    $confirmed = app(ConfirmSubscriptionAction::class)->execute($subscription, new ConsentContext('confirmation', ConsentBasis::Consent));
    $again = app(ConfirmSubscriptionAction::class)->execute($subscription, new ConsentContext('confirmation'));

    expect($statusWhileWaiting)->toBe(SubscriptionStatus::Pending)
        ->and($optOutWhileWaiting)->not->toBeNull()
        ->and($confirmed)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($subscription->fresh())
        ->status->toBe(SubscriptionStatus::Subscribed)
        ->confirmed_at->not->toBeNull()
        ->and($subscriber->fresh()?->opted_out_at)->toBeNull()
        ->and(consentActions($subscriber))->toBe(['subscribe', 'confirm', 'opt_in']);
});

it('records an unticked sign-up box as one decline that opts the person out', function (): void {
    $list = CampaignList::factory()->create();
    $subscriber = Subscriber::factory()->create();

    $subscription = app(DeclineAction::class)->execute($subscriber, $list, new ConsentContext('registration', noticeVersion: 'v1', accepted: false));

    expect($subscription->status)->toBe(SubscriptionStatus::Subscribed)
        ->and($subscriber->fresh()?->opted_out_at)->not->toBeNull()
        ->and(ConsentRecord::query()->sole())
        ->action->toBe(ConsentAction::Decline)
        ->list_id->toBe($list->id)
        ->notice_version->toBe('v1');
});

it('opts out and back in, blocks and unblocks, recording each change once', function (): void {
    $subscriber = Subscriber::factory()->create(['soft_bounces' => 2]);
    $context = new ConsentContext('agent');

    $results = [
        app(OptOutAction::class)->execute($subscriber, $context),
        app(OptOutAction::class)->execute($subscriber, $context),
        app(OptInAction::class)->execute($subscriber, $context),
        app(OptInAction::class)->execute($subscriber, $context),
        app(BlockAction::class)->execute($subscriber, BlockReason::Complaint, $context),
        app(BlockAction::class)->execute($subscriber, BlockReason::HardBounce, $context),
    ];
    $blockedFor = $subscriber->fresh()?->blocked_reason;

    $results[] = app(UnblockAction::class)->execute($subscriber, $context);
    $results[] = app(UnblockAction::class)->execute($subscriber, $context);

    expect($results)->toBe([true, false, true, false, true, false, true, false])
        ->and($blockedFor)->toBe(BlockReason::Complaint)
        ->and($subscriber->fresh())
        ->blocked_at->toBeNull()
        ->blocked_reason->toBeNull()
        ->soft_bounces->toBe(0)
        ->opted_out_at->toBeNull()
        ->and(consentActions($subscriber))->toBe(['opt_out', 'opt_in', 'block', 'unblock'])
        ->and(ConsentRecord::query()->where('action', ConsentAction::Unblock)->sole()->meta)->toBe(['reason' => 'complaint']);
});

it('takes a subscriber off a list, and adding them again starts afresh', function (): void {
    $list = CampaignList::factory()->create();
    $subscriber = Subscriber::factory()->create();
    $context = new ConsentContext('agent');
    app(SubscribeAction::class)->execute($subscriber, $list, $context);

    $removed = app(RemoveFromListAction::class)->execute($subscriber, $list, $context);
    $again = app(RemoveFromListAction::class)->execute($subscriber, $list, $context);
    $readded = app(SubscribeAction::class)->execute($subscriber, $list, $context);

    expect($removed)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($readded->wasRecentlyCreated)->toBeTrue()
        ->and(consentActions($subscriber))->toBe(['subscribe', 'unsubscribe', 'subscribe']);
});

it('refuses to add a contact to a dynamic list, or take anyone off one, by hand', function (): void {
    $subscription = Subscription::factory()->for(CampaignList::factory()->dynamic(), 'list')->create();
    $context = new ConsentContext('agent');

    expect(fn (): bool => app(RemoveFromListAction::class)->execute($subscription->subscriber, $subscription->list, $context))
        ->toThrow(CampaignsException::class, 'follows the app\'s records')
        ->and(fn (): Subscription => app(SubscribeAction::class)->execute(Subscriber::factory()->create(), $subscription->list, $context))
        ->toThrow(CampaignsException::class, 'follows the app\'s records')
        ->and(Subscription::query()->count())->toBe(1);
});
