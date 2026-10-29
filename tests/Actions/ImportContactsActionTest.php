<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\Actions\ImportContactsAction;
use Mozex\Campaigns\Actions\OptInAction;
use Mozex\Campaigns\Actions\SubscribeAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Imports\ImportedContact;
use Mozex\Campaigns\Imports\ImportResult;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;

/**
 * @param  iterable<ImportedContact|array<array-key, mixed>>  $contacts
 */
function import(iterable $contacts, ?CampaignList $list, bool $dryRun = false, ?string $only = null, ?ConsentContext $context = null): ImportResult
{
    $context ??= new ConsentContext('old-esp', meta: ['file' => 'export.csv']);

    return app(ImportContactsAction::class)->execute($contacts, $list, $context, $dryRun, $only);
}

/**
 * The counts that aren't zero, for reading a result at a glance.
 *
 * @return array<string, int>
 */
function importCounts(ImportResult $result): array
{
    return array_filter($result->counts);
}

it('brings contacts onto a static list with their details, engagement and the other system\'s choices, a dry run first', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();
    $list = CampaignList::factory()->create(['key' => 'readers', 'default_basis' => ConsentBasis::Consent]);

    $rows = [
        [
            'email' => ' Reader@Example.com ', 'name' => 'Rea Der', 'first_name' => 'Rea', 'locale' => 'es', 'country' => 'mx',
            'custom_fields' => ['plan' => 'pro'], 'last_opened_at' => '2026-09-01T10:00:00+03:00', 'last_clicked_at' => '',
            'meta' => ['lists' => ['Main'], 'added' => '2024-01-02'],
        ],
        ['email' => 'complainer@example.com', 'blocked' => 'complaint', 'opted_out' => 'yes'],
        ['email' => 'not-an-address', 'name' => 'Nobody'],
        ['email' => 'READER@example.com', 'name' => 'Someone Else'],
        ['email' => 'typo@example.com', 'segment' => 'vip'],
        ['email' => 'future@example.com', 'last_clicked_at' => now()->addDay()->toIso8601String()],
        new ImportedContact('unsubscribed@example.com', optOut: true),
        ['email' => 'zero@example.com', 'last_opened_at' => '0000-00-00 00:00:00'],
        ['email' => 'reader@example.com', 'opted_out' => true],
        ['email' => 'fast-clock@example.com', 'last_active_at' => now()->addMinutes(2)->toIso8601String()],
    ];

    $dryRun = import($rows, $list, dryRun: true);
    $written = [Subscriber::query()->count(), Subscription::query()->count(), ConsentRecord::query()->count()];
    $result = import($rows, $list);

    $reader = Subscriber::findByEmail('reader@example.com');
    $complainer = Subscriber::findByEmail('complainer@example.com');

    expect($written)->toBe([0, 0, 0])
        ->and($dryRun->toArray())->toBe([...$result->toArray(), 'dry_run' => true])
        ->and($result->rows)->toBe(10)
        ->and(importCounts($result))->toBe(['created' => 4, 'joined' => 4, 'blocked' => 1, 'opted_out' => 3])
        ->and($result->suppressed)->toBe(3)
        ->and($result->problems)->toBe([
            'Row 3: it has no email address that can be mailed.',
            'Row 4: the same address as row 1, which counts instead.',
            'Row 5: A contact has no field "segment". Use email, name, first_name, locale, timezone, country, custom_fields, last_opened_at, last_clicked_at, last_active_at, opted_out, blocked, meta.',
            'Row 6: The contact\'s last_clicked_at can\'t be imported: it is in the future.',
            'Row 8: The contact\'s last_opened_at can\'t be imported: it is before 2 January 1970, too early to store.',
            'Row 9: the same address as row 1, which counts instead; this row\'s opt-out or block was added to it.',
        ])
        ->and($reader)
        ->name->toBe('Rea Der')
        ->first_name->toBe('Rea')
        ->locale->toBe('es')
        ->country->toBe('MX')
        ->custom_fields->toBe(['plan' => 'pro'])
        ->last_clicked_at->toBeNull()
        ->opted_out_at->not->toBeNull()
        ->and($reader?->last_opened_at?->toIso8601String())->toBe('2026-09-01T07:00:00+00:00')
        ->and(Subscription::query()->where('subscriber_id', $reader?->id)->sole())
        ->status->toBe(SubscriptionStatus::Subscribed)
        ->basis->toBe(ConsentBasis::Imported)
        ->source->toBe('old-esp')
        ->and(consentActions($reader))->toBe(['subscribe', 'opt_out'])
        ->and(ConsentRecord::query()->where('subscriber_id', $reader?->id)->where('action', ConsentAction::Subscribe)->sole())
        ->basis->toBe(ConsentBasis::Imported)
        // jsonb keeps its own key order.
        ->meta->toEqual(['file' => 'export.csv', 'lists' => ['Main'], 'added' => '2024-01-02'])
        ->and($complainer)
        ->blocked_reason->toBe(BlockReason::Complaint)
        ->opted_out_at->not->toBeNull()
        ->and(consentActions($complainer))->toBe(['subscribe', 'block', 'opt_out'])
        ->and(ConsentRecord::query()->where('subscriber_id', $complainer?->id)->pluck('basis')->unique()->all())->toBe([ConsentBasis::Imported])
        ->and(Subscriber::findByEmail('fast-clock@example.com')?->last_active_at?->equalTo(now()))->toBeTrue()
        ->and(Subscriber::query()->pluck('email')->sort()->values()->all())->toBe(['complainer@example.com', 'fast-clock@example.com', 'reader@example.com', 'unsubscribed@example.com']);
})->with('databases');

it('never speaks for the person, so a context that says they asked clears no opt-out', function (): void {
    $list = CampaignList::factory()->create();
    $optedOut = Subscriber::factory()->optedOut()->create(['email' => 'gone@example.com']);
    Subscription::factory()->for($optedOut)->create();

    $result = import([['email' => 'gone@example.com']], $list, context: new ConsentContext('chat-tool', ConsentBasis::Consent, accepted: true));

    expect(importCounts($result))->toBe(['joined' => 1])
        ->and($optedOut->fresh()?->opted_out_at)->not->toBeNull()
        ->and(consentActions($optedOut))->toBe(['subscribe'])
        ->and(ConsentRecord::query()->sole()->basis)->toBe(ConsentBasis::Consent);
});

it('keeps a later row\'s opt-out whatever else it holds, and refuses what the database can\'t store', function (string $driver): void {
    useDatabase($driver);
    $list = CampaignList::factory()->create();

    $rows = [
        ['email' => 'a@example.com'],
        ['email' => 'A@example.com', 'opted_out' => 'yes', 'last_opened_at' => '0000-00-00 00:00:00'],
        ['email' => 'latin@example.com', 'custom_fields' => ['city' => "Z\xFCrich"]],
        ['email' => 'nul@example.com', 'name' => "Nu\0l"],
        ['email' => "jos\xE9@example.com"],
        ['email' => 'a@example.com', 'opted_out' => true],
        ['email' => 'b@example.com', 'blocked' => 'hard_bounce'],
        ['email' => 'b@example.com', 'blocked' => 'complaint'],
        ['email' => 'c@example.com', 'custom_fields' => ['bad name' => 1]],
        ['email' => 'd@example.com', 'custom_fields' => ['nested' => ['a' => 1]]],
    ];

    $dryRun = import($rows, $list, dryRun: true);
    $result = import($rows, $list);

    expect($dryRun->toArray())->toBe([...$result->toArray(), 'dry_run' => true])
        ->and(importCounts($result))->toBe(['created' => 2, 'joined' => 2, 'blocked' => 1, 'opted_out' => 1])
        ->and($result->suppressed)->toBe(2)
        ->and($result->problems)->toBe([
            'Row 2: the same address as row 1, which counts instead; this row\'s opt-out or block was added to it.',
            'Row 3: The custom field "city" is text of at most 1000 characters, a whole number, true, false or null.',
            'Row 4: The contact\'s name can\'t be imported: it holds a NUL character.',
            'Row 5: it has no email address that can be mailed.',
            'Row 6: the same address as row 1, which counts instead.',
            'Row 8: the same address as row 7, which counts instead.',
            'Row 9: The custom field names are letters, digits and underscores, starting with a letter or an underscore.',
            'Row 10: The custom field "nested" is text of at most 1000 characters, a whole number, true, false or null.',
        ])
        ->and(Subscriber::findByEmail('a@example.com')?->opted_out_at)->not->toBeNull()
        ->and(Subscriber::findByEmail('b@example.com')?->blocked_reason)->toBe(BlockReason::HardBounce)
        ->and(Subscriber::query()->count())->toBe(2);
})->with('databases');

it('lets a row that found nobody leave the address to the next row that has it', function (): void {
    $result = import([
        ['email' => 'stranger@example.com', 'last_opened_at' => now()->subDay()->toIso8601String()],
        ['email' => 'stranger@example.com', 'blocked' => 'hard_bounce'],
    ], null);

    expect(importCounts($result))->toBe(['created' => 1, 'blocked' => 1, 'not_found' => 1])
        ->and($result->problems)->toBe([])
        ->and(Subscriber::findByEmail('stranger@example.com')?->blocked_reason)->toBe(BlockReason::HardBounce);
});

it('runs a row again after a deadlock, and leaves a caller\'s transaction as it found it', function (): void {
    app()->bind(SubscribeAction::class, fn (): SubscribeAction => new class(app(OptInAction::class)) extends SubscribeAction
    {
        public static int $calls = 0;

        public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
        {
            if (self::$calls++ === 0) {
                throw new class('Deadlock found when trying to get lock') extends PDOException
                {
                    protected $code = '40001';
                };
            }

            return parent::execute($subscriber, $list, $context);
        }
    });
    $list = CampaignList::factory()->create();

    $retried = import([['email' => 'a@example.com']], $list);
    $level = DB::transactionLevel();

    DB::beginTransaction();
    $inside = import([['email' => 'b@example.com']], $list, dryRun: true);
    $levelInside = DB::transactionLevel();
    DB::rollBack();

    expect(importCounts($retried))->toBe(['created' => 1, 'joined' => 1])
        ->and(Subscriber::query()->pluck('email')->all())->toBe(['a@example.com'])
        ->and([$level, $levelInside])->toBe([0, 1])
        ->and(importCounts($inside))->toBe(['created' => 1, 'joined' => 1]);
});

it('stops at a row the database refuses, naming the row and never its address', function (): void {
    app()->bind(SubscribeAction::class, fn (): SubscribeAction => new class(app(OptInAction::class)) extends SubscribeAction
    {
        public function execute(Subscriber $subscriber, CampaignList $list, ConsentContext $context): Subscription
        {
            throw new QueryException('testing', 'insert into campaign_list_subscriptions values (?)', [$subscriber->email], new class('Integrity constraint violation') extends PDOException
            {
                protected $code = '23000';
            });
        }
    });

    $result = import([['email' => 'secret@example.com'], ['email' => 'next@example.com']], CampaignList::factory()->create());

    expect($result->problems)->toBe(['Row 1: the database refused it (SQLSTATE 23000), so the import stopped there.'])
        ->and($result->rows)->toBe(1)
        ->and(Subscriber::query()->count())->toBe(0);
});

it('turns a contact a generator makes with an impossible date into a problem for its row', function (): void {
    $contacts = (function (): Generator {
        yield new ImportedContact('ahead@example.com', lastClickedAt: now()->addHour());
        yield new ImportedContact('fine@example.com');
    })();

    $result = import($contacts, CampaignList::factory()->create());

    expect($result->problems)->toBe(['Row 1: The contact\'s last_clicked_at can\'t be imported: it is in the future.'])
        ->and(Subscriber::query()->pluck('email')->all())->toBe(['fine@example.com']);
});

it('never overwrites what the app knows or clears anyone\'s choice, and moves engagement only forward', function (): void {
    $this->freezeSecond();
    $list = CampaignList::factory()->create();
    $member = Member::query()->create(['email' => 'member@example.com', 'name' => 'Real Name', 'locale' => 'en']);
    $account = Subscriber::findFor($member);
    $account?->forceFill(['last_clicked_at' => now()->subDays(10), 'opted_out_at' => now()->subYear()])->save();
    $contact = Subscriber::factory()->blocked()->create([
        'email' => 'contact@example.com', 'name' => 'Kept', 'locale' => null, 'custom_fields' => ['a' => 1],
    ]);
    Subscription::factory()->for($contact)->for($list, 'list')->unsubscribed()->create();
    $before = [consentActions($account), consentActions($contact)];

    $rows = [
        [
            'email' => 'member@example.com', 'name' => 'Other Name', 'locale' => 'tr', 'custom_fields' => ['x' => 1],
            'last_clicked_at' => now()->subDays(20)->toIso8601String(), 'last_opened_at' => now()->subDays(5)->toIso8601String(),
        ],
        ['email' => 'contact@example.com', 'name' => 'New', 'locale' => 'fr', 'custom_fields' => ['a' => 2, 'b' => 3]],
    ];

    $first = import($rows, $list);
    $again = import($rows, $list);
    $account = $account?->fresh();
    $contact = $contact->fresh();

    expect(importCounts($first))->toBe(['updated' => 2, 'joined' => 1, 'already_on_list' => 1])
        ->and(importCounts($again))->toBe(['already_on_list' => 2])
        ->and($first->suppressed)->toBe(2)
        ->and($account)
        ->name->toBe('Real Name')
        ->locale->toBe('en')
        ->custom_fields->toBeNull()
        ->opted_out_at->not->toBeNull()
        ->and($account?->last_clicked_at?->equalTo(now()->subDays(10)))->toBeTrue()
        ->and($account?->last_opened_at?->equalTo(now()->subDays(5)))->toBeTrue()
        ->and($contact)
        ->name->toBe('Kept')
        ->locale->toBe('fr')
        ->custom_fields->toBe(['a' => 1, 'b' => 3])
        ->blocked_at->not->toBeNull()
        ->and(Subscription::query()->where('subscriber_id', $contact?->id)->sole()->status)->toBe(SubscriptionStatus::Unsubscribed)
        ->and([consentActions($account), consentActions($contact)])->toBe([[...$before[0], 'subscribe'], $before[1]]);
});

it('leaves an address kept only for its suppression as it is, a person who left getting nothing back', function (): void {
    $list = CampaignList::factory()->create();
    $member = Member::query()->create(['email' => 'left@example.com', 'name' => 'Left Us']);
    Subscriber::findFor($member)?->forceFill(['opted_out_at' => now()])->save();
    $member->forceDelete();
    $bounced = Subscriber::factory()->blocked()->create(['email' => 'bounced@example.com', 'name' => null]);

    $result = import([
        ['email' => 'left@example.com', 'name' => 'Left Us', 'last_opened_at' => now()->subDay()->toIso8601String()],
        ['email' => 'bounced@example.com', 'name' => 'Bo Unced', 'blocked' => 'hard_bounce', 'opted_out' => true],
    ], $list);

    expect(importCounts($result))->toBe(['opted_out' => 1, 'kept_suppressed' => 2])
        ->and($result->suppressed)->toBe(2)
        ->and(Subscriber::findByEmail('left@example.com'))
        ->name->toBeNull()
        ->last_opened_at->toBeNull()
        ->opted_out_at->not->toBeNull()
        ->and(Subscriber::findByEmail('bounced@example.com'))
        ->name->toBeNull()
        ->opted_out_at->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
});

it('records opt-outs and blocks without a list, keeping only the address, and adds engagement to subscribers that exist', function (): void {
    $this->freezeSecond();
    $member = Member::query()->create(['email' => 'member@example.com']);
    $contact = Subscriber::factory()->create(['email' => 'contact@example.com']);

    $result = import([
        ['email' => 'member@example.com', 'last_active_at' => now()->subDay()->toIso8601String(), 'name' => 'Not Used'],
        ['email' => 'stranger@example.com', 'last_opened_at' => now()->subDay()->toIso8601String()],
        ['email' => 'bounced@example.com', 'name' => 'Not Kept', 'blocked' => 'hard_bounce', 'last_opened_at' => now()->subDay()->toIso8601String()],
        ['email' => 'contact@example.com', 'opted_out' => '1'],
    ], null);

    expect(importCounts($result))->toBe(['created' => 1, 'updated' => 1, 'blocked' => 1, 'opted_out' => 1, 'not_found' => 1])
        ->and($result->suppressed)->toBe(2)
        ->and(Subscriber::findFor($member)?->last_active_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and(Subscriber::findFor($member)?->name)->toBeNull()
        ->and(Subscriber::findByEmail('stranger@example.com'))->toBeNull()
        ->and(Subscriber::findByEmail('bounced@example.com'))
        ->name->toBeNull()
        ->last_opened_at->toBeNull()
        ->blocked_reason->toBe(BlockReason::HardBounce)
        ->and($contact->fresh()?->opted_out_at)->not->toBeNull()
        // Only the member's own place on its dynamic list.
        ->and(Subscription::query()->pluck('subscriber_id')->all())->toBe([Subscriber::findFor($member)?->id]);
});

it('imports only the row with a given address, to try an import on one contact first', function (): void {
    $list = CampaignList::factory()->create();
    $rows = [['email' => 'a@example.com'], ['email' => 'B@example.com', 'name' => 'Bea'], ['email' => 'c@example.com']];

    $one = import($rows, $list, only: ' b@EXAMPLE.com');
    $none = import($rows, $list, only: 'd@example.com');

    expect($one->rows)->toBe(1)
        ->and(importCounts($one))->toBe(['created' => 1, 'joined' => 1])
        ->and(Subscriber::query()->pluck('email')->all())->toBe(['b@example.com'])
        ->and($none->rows)->toBe(0)
        ->and($none->problems)->toBe(['No row has that address.'])
        ->and(fn () => import($rows, $list, only: 'not an address'))->toThrow(CampaignsException::class, 'not an email address');
});

it('refuses a dynamic list, which follows the app\'s records only', function (): void {
    $list = CampaignList::factory()->dynamic()->create(['key' => 'accounts']);

    expect(fn () => import([['email' => 'a@example.com']], $list))
        ->toThrow(CampaignsException::class, 'The list "accounts" follows the app\'s records');
});

it('leaves contacts waiting on a double opt-in list and mails nobody', function (): void {
    config()->set('campaigns.enabled', true);
    Mail::fake();
    Queue::fake();
    $list = CampaignList::factory()->doubleOptIn()->create();

    import([['email' => 'a@example.com']], $list);

    expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Pending);
    Mail::assertNothingOutgoing();
    Queue::assertNothingPushed();
});

it('names a row by its number, never by its address', function (): void {
    $result = import([
        ['email' => 'secret.person@example.com', 'blocked' => 'gone'],
        ['email' => 'secret.person@example.com'],
        ['email' => 'other.secret@example.com', 'last_opened_at' => 'whenever'],
    ], CampaignList::factory()->create());

    expect($result->problems)->toHaveCount(2)
        ->and(implode(' ', $result->problems))->not->toContain('@')
        ->and($result->problems[0])->toStartWith('Row 1: The contact\'s blocked can\'t be imported: it is not one of hard_bounce');
});
