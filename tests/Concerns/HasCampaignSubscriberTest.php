<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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

/**
 * Custom fields no filter could find, which every other path refuses.
 */
class MemberListWithBadFields extends MemberList
{
    public function fields(Model $record): array
    {
        return [...parent::fields($record), 'custom_fields' => ['signup-source' => 'ads']];
    }
}

/**
 * Fails the way a deadlock does: Laravel recognises one by its message.
 */
class MemberListThatDeadlocks extends MemberList
{
    public function name(): string
    {
        throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
    }
}

/**
 * Fails after a write, inside the sync's transaction, the way a database
 * error would.
 */
class MemberListThatFails extends MemberList
{
    public function name(): string
    {
        DB::table('campaign_lists')->insert([
            'key' => 'written-before-the-failure',
            'name' => 'Written before the failure',
            'kind' => 'static',
            'default_status' => 'subscribed',
            'default_basis' => 'consent',
        ]);

        DB::select('select * from a_table_that_does_not_exist');

        return parent::name();
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
    $optOutAtSignUp = $subscriber?->opted_out_at;
    $actionsAtSignUp = consentActions($subscriber);

    $member->update(['name' => 'Jane Smith']);
    app(SubscribeAction::class)->execute($subscriber, CampaignList::factory()->create(), new ConsentContext('import'));
    $afterSyncAndImport = $subscriber->fresh()?->opted_out_at;

    app(SubscribeAction::class)->execute($subscriber, CampaignList::query()->where('key', 'members')->sole(), new ConsentContext('account-switch', accepted: true));

    expect($optOutAtSignUp)->not->toBeNull()
        ->and($actionsAtSignUp)->toBe(['decline'])
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
        ->and(consentActions($contact))->toBe(['subscribe', 'opt_in'])
        ->and(ConsentRecord::query()->where('action', ConsentAction::OptIn)->sole()->basis)->toBe(ConsentBasis::SoftOptIn);
});

it('records the choice of a record that cannot join its list yet', function (): void {
    $member = Member::withCampaignConsent(
        new ConsentContext('registration', accepted: false),
        fn (): Member => Member::query()->create(['email' => 'jane@example.com', 'banned_at' => now()]),
    );
    $subscriber = Subscriber::findFor($member);
    $whileBanned = Subscription::query()->sole()->status;

    $member->update(['banned_at' => null]);

    expect($whileBanned)->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Subscribed)
        ->and($subscriber?->fresh()?->opted_out_at)->not->toBeNull()
        ->and(consentActions($subscriber))->toBe(['decline'])
        ->and(ConsentRecord::query()->sole()->source)->toBe('registration');
});

it('takes a record off its list while its address cannot be synced', function (): void {
    $jane = Member::query()->create(['email' => 'jane@example.com']);
    $john = Member::query()->create(['email' => 'john@example.com']);
    $johnsRow = Subscriber::findFor($john);

    $jane->update(['email' => null]);
    $john->update(['email' => 'jane@example.com']);
    $whileBroken = Subscription::query()->orderBy('id')->pluck('status')->all();

    $jane->update(['email' => 'jane@example.org']);
    $john->update(['name' => 'John Doe']);

    expect($whileBroken)->toBe([SubscriptionStatus::Unsubscribed, SubscriptionStatus::Unsubscribed])
        ->and(Subscription::query()->orderBy('id')->pluck('status')->all())->toBe([SubscriptionStatus::Subscribed, SubscriptionStatus::Subscribed])
        ->and($johnsRow?->fresh()?->email)->toBe('jane@example.com');
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

it('reports a failure instead of failing the app\'s own save', function (string $broken): void {
    config()->set('campaigns.list_sources', ['members' => $broken, 'other' => 'Missing\\ListSource']);
    Exceptions::fake();

    $member = Member::query()->create(['email' => 'jane@example.com']);

    expect($member->exists)->toBeTrue()
        ->and(Subscriber::query()->count())->toBe(0);

    Exceptions::assertReported(fn (BindingResolutionException $exception): bool => str_contains($exception->getMessage(), 'Missing\\ListSource'));
    Exceptions::assertReported(CampaignsException::class);
})->with([MemberListWithPhone::class, Member::class]);

it('leaves out custom fields a source gives that the rules refuse, still syncing the record', function (): void {
    config()->set('campaigns.list_sources', ['members' => MemberListWithBadFields::class]);
    Exceptions::fake();

    $member = Member::query()->create(['email' => 'jane@example.com']);
    $member->update(['banned_at' => now()]);

    // The ban still takes the record off its list.
    expect(Subscriber::findFor($member))
        ->custom_fields->toBeNull()
        ->and(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Unsubscribed);

    Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), MemberListWithBadFields::class.' gave custom fields that were left out'));
});

it('rolls a failure back to its own savepoint, leaving the app\'s transaction usable', function (string $driver): void {
    useDatabase($driver);
    config()->set('campaigns.list_sources', ['members' => MemberListThatFails::class]);
    Exceptions::fake();

    $member = DB::transaction(function (): Member {
        $member = Member::query()->create(['email' => 'jane@example.com']);
        $member->update(['name' => 'Jane Doe']);

        return $member;
    });

    expect($member->fresh()?->name)->toBe('Jane Doe')
        ->and(CampaignList::query()->count())->toBe(0);

    // Reported by its code alone: the database's message holds the SQL with
    // the person's address.
    Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), 'SQLSTATE') && ! str_contains($exception->getMessage(), '@'));
    Exceptions::assertNotReported(QueryException::class);
})->with('databases');

it('passes a deadlock on to the app\'s transaction, and only reports one outside it', function (): void {
    config()->set('campaigns.list_sources', ['members' => MemberListThatDeadlocks::class]);
    Exceptions::fake();

    expect(fn (): Member => DB::transaction(fn (): Member => Member::query()->create(['email' => 'jane@example.com'])))
        ->toThrow(DeadlockException::class)
        ->and(Member::query()->count())->toBe(0);

    Exceptions::assertNothingReported();

    Member::query()->create(['email' => 'john@example.com']);

    expect(Member::query()->count())->toBe(1);

    Exceptions::assertReported(fn (CampaignsException $exception): bool => str_contains($exception->getMessage(), 'SQLSTATE'));
    Exceptions::assertNotReported(DeadlockException::class);
});
