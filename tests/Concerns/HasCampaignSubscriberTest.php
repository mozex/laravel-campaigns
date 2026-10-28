<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Exceptions;
use Mozex\Campaigns\Actions\OptOutAction;
use Mozex\Campaigns\Actions\SubscribeAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;
use Mozex\Campaigns\Tests\Fixtures\MemberList;

class MemberListWithPhone extends MemberList
{
    public function fields(Model $record): array
    {
        return [...parent::fields($record), 'phone' => '555'];
    }
}

function signUp(string $email, ?bool $accepted): Member
{
    return Member::withCampaignConsent(
        new ConsentContext('registration', ConsentBasis::SoftOptIn, 'v1', $accepted, '203.0.113.9', 'Browser/1.0'),
        fn (): Member => Member::query()->create(['email' => $email, 'name' => 'Jane Doe', 'locale' => 'en', 'country' => 'gb']),
    );
}

it('puts a new record on its list under the consent it was created with', function (): void {
    $member = signUp('Jane@Example.com', accepted: true);
    $staffMade = Member::query()->create(['email' => 'staff-made@example.com']);

    $subscriber = Subscriber::findFor($member);
    $staffRecord = ConsentRecord::query()->where('subscriber_id', Subscriber::findFor($staffMade)?->id)->sole();

    expect(CampaignList::query()->sole())
        ->key->toBe('members')
        ->name->toBe('Members')
        ->and($subscriber)
        ->email->toBe('jane@example.com')
        ->name->toBe('Jane Doe')
        ->first_name->toBe('Jane')
        ->locale->toBe('en')
        ->country->toBe('GB')
        ->opted_out_at->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $subscriber?->id)->sole())
        ->status->toBe(SubscriptionStatus::Subscribed)
        ->basis->toBe(ConsentBasis::SoftOptIn)
        ->source->toBe('registration')
        ->and(ConsentRecord::query()->where('subscriber_id', $subscriber?->id)->sole())
        ->action->toBe(ConsentAction::Subscribe)
        ->notice_version->toBe('v1')
        ->ip_address->toBe('203.0.113.9')
        ->and($staffRecord)
        ->source->toBe('staff')
        ->basis->toBe(ConsentBasis::SoftOptIn)
        ->notice_version->toBeNull();
});

it('records an unticked box as one decline that only the person\'s own act undoes', function (): void {
    $member = signUp('jane@example.com', accepted: false);
    $subscriber = Subscriber::findFor($member);
    $declined = [$subscriber?->opted_out_at, consentActions($subscriber)];

    $member->update(['name' => 'Jane Smith']);
    app(SubscribeAction::class)->execute($subscriber, CampaignList::factory()->create(), new ConsentContext('import'));
    $afterSyncAndImport = $subscriber->fresh()?->opted_out_at;

    app(SubscribeAction::class)->execute($subscriber, CampaignList::query()->where('key', 'members')->sole(), new ConsentContext('account-switch', accepted: true));

    expect($declined[0])->not->toBeNull()
        ->and($declined[1])->toBe(['decline'])
        ->and($afterSyncAndImport)->not->toBeNull()
        ->and($subscriber->fresh()?->opted_out_at)->toBeNull()
        ->and(consentActions($subscriber))->toBe(['decline', 'subscribe', 'opt_in']);
});

it('clears an earlier unsubscribe when the box is ticked at sign-up', function (): void {
    $contact = Subscriber::factory()->optedOut()->create(['email' => 'jane@example.com']);

    $member = signUp('jane@example.com', accepted: true);

    expect($contact->fresh())
        ->opted_out_at->toBeNull()
        ->name->toBe('Jane Doe')
        ->and($contact->fresh()?->subscribable?->is($member))->toBeTrue()
        ->and(consentActions($contact))->toBe(['subscribe', 'opt_in']);
});

it('keeps the subscriber\'s details and address in step with the record', function (): void {
    $member = Member::query()->create(['email' => 'jane@example.com', 'name' => 'Jane Doe']);
    $subscriber = Subscriber::findFor($member);

    $member->update(['email' => 'Jane.Smith@Example.com', 'name' => 'Jane Smith', 'country' => '1x']);

    expect($subscriber?->fresh())
        ->email->toBe('jane.smith@example.com')
        ->name->toBe('Jane Smith')
        ->first_name->toBe('Jane')
        ->country->toBeNull()
        ->and(Subscriber::query()->count())->toBe(1);
});

it('takes a banned record off the list and puts it back, leaving consent alone', function (): void {
    $member = Member::query()->create(['email' => 'jane@example.com']);
    $subscriber = Subscriber::findFor($member);
    app(OptOutAction::class)->execute($subscriber, new ConsentContext('unsubscribe-page'));

    $member->update(['banned_at' => now()]);
    $banned = Subscription::query()->sole()->status;
    $member->update(['banned_at' => null]);

    expect($banned)->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Subscribed)
        ->and($subscriber->fresh()?->opted_out_at)->not->toBeNull()
        ->and(consentActions($subscriber))->toBe(['subscribe', 'opt_out']);
});

it('takes a soft-deleted record off its list, and forgets the person once it is gone', function (): void {
    $member = Member::query()->create(['email' => 'jane@example.com', 'name' => 'Jane Doe']);

    $member->delete();
    $softDeleted = [Subscription::query()->sole()->status, Subscriber::query()->sole()->name];

    $member->restore();
    $restored = Subscription::query()->sole()->status;

    $member->forceDelete();

    expect($softDeleted)->toBe([SubscriptionStatus::Unsubscribed, 'Jane Doe'])
        ->and($restored)->toBe(SubscriptionStatus::Subscribed)
        ->and(Subscriber::query()->count())->toBe(0)
        ->and(ConsentRecord::query()->sole()->subscriber_id)->toBeNull();
});

it('reports a failure instead of failing the app\'s own save', function (): void {
    config()->set('campaigns.list_sources', ['members' => MemberListWithPhone::class]);
    Exceptions::fake();

    $member = Member::query()->create(['email' => 'jane@example.com']);

    expect($member->exists)->toBeTrue()
        ->and(Subscriber::query()->count())->toBe(0);

    Exceptions::assertReported(CampaignsException::class);
});
