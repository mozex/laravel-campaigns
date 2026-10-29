<?php

declare(strict_types=1);

use Mozex\Campaigns\Actions\ChangeEmailAction;
use Mozex\Campaigns\Actions\SubscribeAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\CampaignMessage;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;

function changeEmail(Subscriber $subscriber, string $email): Subscriber
{
    return app(ChangeEmailAction::class)->execute($subscriber, $email, new ConsentContext('sync'));
}

it('changes the address in place when nothing has to stay with the old one', function (): void {
    // The old address's soft bounces stay with it, as a block would.
    // A double opt-in still waits: its link went to the old address.
    $subscriber = Subscriber::factory()->create(['email' => 'old@example.com', 'soft_bounces' => 2, 'confirmation_sent_at' => now()]);
    $waiting = Subscription::factory()->for($subscriber)->pending()->create();
    $pending = CampaignMessage::factory()->for($subscriber)->create();
    $sent = CampaignMessage::factory()->for($subscriber)->create(['status' => MessageStatus::Sent]);

    $result = changeEmail($subscriber, ' New@Example.com');

    expect($result->is($subscriber))->toBeTrue()
        ->and(Subscriber::query()->sole())
        ->email->toBe('new@example.com')
        ->soft_bounces->toBe(0)
        ->confirmation_sent_at->toBeNull()
        ->and($waiting->confirm_token_hash)->not->toBeNull()
        ->and(Subscription::query()->sole())
        ->subscriber_id->toBe($subscriber->id)
        ->status->toBe(SubscriptionStatus::Pending)
        ->confirm_token_hash->toBeNull()
        ->and($pending->fresh()?->status)->toBe(MessageStatus::Skipped)
        ->and($sent->fresh()?->status)->toBe(MessageStatus::Sent);
});

it('moves the person to a new row and leaves a block with the old address', function (): void {
    $member = Member::query()->create(['email' => 'old@example.com', 'name' => 'Jane Doe']);
    $old = Subscriber::findFor($member);
    $old?->forceFill(['blocked_at' => now(), 'blocked_reason' => BlockReason::HardBounce, 'custom_fields' => ['plan' => 'pro']])->save();
    ConsentRecord::record($old, ConsentAction::Block, new ConsentContext('webhook'));

    $new = changeEmail($old, 'new@example.com');

    expect($new->is($old))->toBeFalse()
        ->and($new)
        ->email->toBe('new@example.com')
        ->name->toBe('Jane Doe')
        ->custom_fields->toBe(['plan' => 'pro'])
        ->blocked_at->toBeNull()
        ->and($new->subscribable?->is($member))->toBeTrue()
        ->and(Subscription::query()->sole()->subscriber_id)->toBe($new->id)
        ->and($old->fresh())
        ->email->toBe('old@example.com')
        ->blocked_reason->toBe(BlockReason::HardBounce)
        ->subscribable_type->toBeNull()
        ->name->toBeNull()
        ->custom_fields->toBeNull()
        ->and(ConsentRecord::query()->where('subscriber_id', $new->id)->sole()->action)->toBe(ConsentAction::Subscribe)
        ->and(ConsentRecord::query()->where('subscriber_id', $old->id)->sole()->action)->toBe(ConsentAction::Block);
});

it('joins an address that already has a row, the stricter state winning', function (string $driver): void {
    useDatabase($driver);

    $member = Member::query()->create(['email' => 'old@example.com']);
    $old = Subscriber::findFor($member);
    $old?->forceFill(['opted_out_at' => now()])->save();
    $shared = CampaignList::factory()->create();
    $own = CampaignList::factory()->create();
    $bothWaiting = CampaignList::factory()->create();
    $moved = CampaignList::factory()->create();
    Subscription::factory()->for($old)->for($shared, 'list')->pending()->create();
    Subscription::factory()->for($old)->for($own, 'list')->create();
    Subscription::factory()->for($old)->for($bothWaiting, 'list')->pending()->create();
    $waiting = Subscription::factory()->for($old)->for($moved, 'list')->pending()->create();
    $contact = Subscriber::factory()->create(['email' => 'new@example.com']);
    app(SubscribeAction::class)->execute($contact, $shared, new ConsentContext('import'));
    $theirs = Subscription::factory()->for($contact)->for($bothWaiting, 'list')->pending()->create();

    $result = changeEmail($old, 'new@example.com');
    $tokens = Subscription::query()->where('subscriber_id', $contact->id)->pluck('confirm_token_hash', 'list_id');

    expect($result->is($contact))->toBeTrue()
        ->and($result->fresh())
        ->opted_out_at->not->toBeNull()
        ->and($result->fresh()?->subscribable?->is($member))->toBeTrue()
        // A waiting confirmation proves nothing against the address's own
        // subscription, since its link went to the old address.
        ->and(Subscription::query()->where('subscriber_id', $contact->id)->whereIn('list_id', [$shared->id, $own->id, $bothWaiting->id, $moved->id])->orderBy('list_id')->pluck('status', 'list_id')->all())->toBe([
            $shared->id => SubscriptionStatus::Subscribed,
            $own->id => SubscriptionStatus::Subscribed,
            $bothWaiting->id => SubscriptionStatus::Pending,
            $moved->id => SubscriptionStatus::Pending,
        ])
        // The address's own link still confirms; the old address's doesn't.
        ->and($tokens[$bothWaiting->id])->toBe($theirs->confirm_token_hash)->not->toBeNull()
        ->and($waiting->confirm_token_hash)->not->toBeNull()
        ->and($tokens[$moved->id])->toBeNull()
        ->and($old->fresh())
        ->email->toBe('old@example.com')
        ->opted_out_at->not->toBeNull()
        ->subscribable_type->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $old->id)->count())->toBe(0)
        ->and(ConsentRecord::query()->where('subscriber_id', $old->id)->sole())
        ->action->toBe(ConsentAction::OptOut)
        ->meta->toBe(['address_changed' => true]);
})->with('databases');

it('deletes the old row when it held nothing to keep', function (): void {
    $member = Member::query()->create(['email' => 'old@example.com']);
    $old = Subscriber::findFor($member);
    $contact = Subscriber::factory()->create(['email' => 'new@example.com']);

    changeEmail($old, 'new@example.com');

    expect(Subscriber::query()->pluck('email')->all())->toBe(['new@example.com'])
        ->and($contact->fresh()?->subscribable?->is($member))->toBeTrue()
        ->and(ConsentRecord::query()->sole()->subscriber_id)->toBe($contact->id);
});

it('takes the person\'s engagement to the row they join, so a reader isn\'t sunset by an address change', function (): void {
    $this->freezeSecond();
    $member = Member::query()->create(['email' => 'old@example.com']);
    $old = Subscriber::findFor($member);
    $old?->forceFill([
        'last_opened_at' => now()->subDays(10),
        'last_clicked_at' => now()->subDays(400),
        'last_active_at' => now()->subDays(30),
        'first_mailed_at' => now()->subMonths(20),
    ])->save();
    $contact = Subscriber::factory()->create([
        'email' => 'new@example.com',
        'last_opened_at' => now()->subDays(500),
        'last_clicked_at' => now()->subDays(20),
        'first_mailed_at' => now()->subMonths(18),
    ]);

    $moved = changeEmail($old, 'new@example.com')->fresh();

    expect($moved)
        ->last_opened_at->toEqual(now()->subDays(10))
        ->last_clicked_at->toEqual(now()->subDays(20))
        ->last_active_at->toEqual(now()->subDays(30))
        ->first_mailed_at->toEqual(now()->subMonths(20))
        ->and($moved?->isSunset())->toBeFalse();
});

it('keeps mail sent to the old address with the person, so its unsubscribe link still reaches them', function (): void {
    $member = Member::query()->create(['email' => 'old@example.com']);
    $old = Subscriber::findFor($member);
    $contact = Subscriber::factory()->create(['email' => 'new@example.com']);
    [$toOld, $token] = sentMessage(['subscriber_id' => $old?->id]);
    [$bothOld] = sentMessage(['subscriber_id' => $old?->id]);
    [$bothNew] = sentMessage(['subscriber_id' => $contact->id, 'campaign_id' => $bothOld->campaign_id]);

    changeEmail($old, 'new@example.com');
    $this->post("/campaigns/u/{$token}", ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($toOld->fresh())
        ->subscriber_id->toBe($contact->id)
        ->email->toBe('old@example.com')
        ->unsubscribed_at->not->toBeNull()
        ->and($contact->fresh()?->opted_out_at)->not->toBeNull()
        // A campaign holds one email per subscriber, so the one that reached
        // both rows stays with the old row, which was deleted.
        ->and($bothOld->fresh())
        ->subscriber_id->toBeNull()
        ->email->toBeNull()
        ->and($bothNew->fresh()?->subscriber_id)->toBe($contact->id);
});

it('decides from the row as it is now, not from a stale copy', function (): void {
    $stale = Subscriber::factory()->create(['email' => 'old@example.com']);
    Subscriber::query()->sole()->forceFill(['blocked_at' => now(), 'blocked_reason' => BlockReason::HardBounce])->save();

    $result = changeEmail($stale, 'new@example.com');

    expect($result->is($stale))->toBeFalse()
        ->and(Subscriber::findByEmail('old@example.com')?->blocked_reason)->toBe(BlockReason::HardBounce)
        ->and(Subscriber::findByEmail('new@example.com')?->blocked_at)->toBeNull();
});

it('refuses an address another record holds, or one that cannot be mailed', function (string $email): void {
    Member::query()->create(['email' => 'held@example.com']);
    $subscriber = Subscriber::factory()->create(['email' => 'mine@example.com']);

    expect(fn (): Subscriber => changeEmail($subscriber, $email))->toThrow(CampaignsException::class)
        ->and($subscriber->fresh()?->email)->toBe('mine@example.com');
})->with(['held@example.com', 'not an address']);
