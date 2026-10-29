<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Mozex\Campaigns\Actions\AddContactsAction;
use Mozex\Campaigns\Actions\CreateUploadAction;
use Mozex\Campaigns\Actions\ImportUploadedContactsAction;
use Mozex\Campaigns\Actions\RemoveContactsAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Enums\UploadPurpose;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;

beforeEach(function (): void {
    Storage::fake('local');
});

describe('adding contacts', function (): void {
    it('adds them as an import would, on the basis given, keeping a suppressed address off', function (string $driver): void {
        useDatabase($driver);
        $list = CampaignList::factory()->doubleOptIn()->create(['key' => 'events', 'default_basis' => ConsentBasis::Consent]);
        // Someone who left: only the address, kept so it is never mailed.
        Subscriber::factory()->create(['email' => 'left@example.com', 'name' => null, 'first_name' => null, 'opted_out_at' => now()]);
        $known = Subscriber::factory()->create(['email' => 'known@example.com', 'name' => 'Known Name', 'country' => null]);

        $result = app(AddContactsAction::class)->execute($list, [
            ['email' => 'New@Example.com', 'name' => 'New Person', 'custom_fields' => ['met_at' => 'expo']],
            ['email' => 'known@example.com', 'name' => 'Other Name', 'country' => 'TR'],
            ['email' => 'left@example.com', 'name' => 'Back Again'],
        ], ConsentBasis::SoftOptIn, new ConsentContext('agent', meta: ['by' => 'laptop']));

        $new = Subscriber::findByEmail('new@example.com');

        expect(array_filter($result->counts))->toBe(['created' => 1, 'updated' => 1, 'joined' => 2, 'kept_suppressed' => 1])
            ->and($result->problems)->toBe([])
            ->and($new)->name->toBe('New Person')->custom_fields->toBe(['met_at' => 'expo'])
            ->and($known->fresh())->name->toBe('Known Name')->country->toBe('TR')
            ->and(Subscription::query()->where('list_id', $list->id)->pluck('status')->unique()->all())->toBe([SubscriptionStatus::Pending])
            ->and(Subscription::query()->where('subscriber_id', $new->id)->sole()->basis)->toBe(ConsentBasis::SoftOptIn)
            ->and(ConsentRecord::query()->where('subscriber_id', $new->id)->sole())
            ->action->toBe(ConsentAction::Subscribe)
            ->basis->toBe(ConsentBasis::SoftOptIn)
            ->source->toBe('agent')
            ->meta->toBe(['by' => 'laptop'])
            ->and(Subscriber::findByEmail('left@example.com'))->name->toBeNull()->lists->toBeEmpty();
    })->with('databases');

    it('refuses an archived or dynamic list, too many contacts, and fields only an import carries', function (): void {
        $context = new ConsentContext('agent');
        $add = fn (CampaignList $list, array $contacts) => app(AddContactsAction::class)->execute($list, $contacts, ConsentBasis::Consent, $context);
        $list = CampaignList::factory()->create(['key' => 'events']);

        expect(fn () => $add(CampaignList::factory()->archived()->create(['key' => 'old']), [['email' => 'a@example.com']]))->toThrow(CampaignsException::class, 'The list "old" is archived.')
            ->and(fn () => $add(CampaignList::factory()->dynamic()->create(['key' => 'members']), [['email' => 'a@example.com']]))->toThrow(CampaignsException::class, 'follows the app\'s records')
            ->and(fn () => $add($list, array_fill(0, 101, ['email' => 'a@example.com'])))->toThrow(CampaignsException::class, 'At most 100 contacts at a time.')
            ->and(fn () => $add($list, []))->toThrow(CampaignsException::class, 'must hold at least one contact')
            ->and(fn () => $add($list, [['email' => 'a@example.com'], ['email' => 'b@example.com', 'opted_out' => true]]))->toThrow(CampaignsException::class, 'The contact 2 can only carry email, name, first_name, locale, timezone, country, custom_fields, not opted_out.')
            ->and(Subscriber::query()->count())->toBe(0);
    });
});

it('takes addresses off a static list, counting what it found', function (string $driver): void {
    useDatabase($driver);
    $list = CampaignList::factory()->create(['key' => 'events']);
    $other = CampaignList::factory()->create(['key' => 'other']);
    $on = subscriberOn([$list, $other], ['email' => 'on@example.com']);
    subscriberOn([$other], ['email' => 'elsewhere@example.com']);

    $counts = app(RemoveContactsAction::class)->execute($list, ['ON@example.com', 'on@example.com', 'elsewhere@example.com', 'nobody@example.com', 'not an address'], new ConsentContext('agent'));

    expect($counts)->toBe(['removed' => 1, 'not_on_list' => 1, 'not_found' => 1, 'invalid' => 1])
        ->and($on->lists()->pluck('key')->all())->toBe(['other'])
        ->and($on->fresh()->opted_out_at)->toBeNull()
        ->and(consentActions($on))->toBe(['unsubscribe'])
        ->and(fn () => app(RemoveContactsAction::class)->execute(CampaignList::factory()->dynamic()->create(), ['on@example.com'], new ConsentContext('agent')))->toThrow(CampaignsException::class, 'follows the app\'s records')
        ->and(fn () => app(RemoveContactsAction::class)->execute($list, array_fill(0, 101, 'on@example.com'), new ConsentContext('agent')))->toThrow(CampaignsException::class, 'At most 100');
})->with('databases');

describe('importing an uploaded CSV', function (): void {
    it('previews without using the upload up, then runs on it and deletes its file', function (string $driver): void {
        useDatabase($driver);
        $list = CampaignList::factory()->create(['key' => 'events']);
        $upload = contactsUpload("email,name,blocked,custom_fields.company\nann@example.com,Ann,,Acme\nbob@example.com,Bob,hard_bounce,\n");
        $context = new ConsentContext('expo-2026');

        $preview = app(ImportUploadedContactsAction::class)->execute($upload->uuid, $list, $context, dryRun: true);
        $afterPreview = [Subscriber::query()->count(), $upload->fresh()->claimed_at];
        $run = app(ImportUploadedContactsAction::class)->execute($upload->uuid, $list, $context, dryRun: false);

        expect($afterPreview)->toBe([0, null])
            ->and($preview->toArray())->toBe([...$run->toArray(), 'dry_run' => true])
            ->and(array_filter($run->counts))->toBe(['created' => 2, 'joined' => 2, 'blocked' => 1])
            ->and(Subscriber::findByEmail('ann@example.com')->custom_fields)->toBe(['company' => 'Acme'])
            ->and(Subscriber::findByEmail('bob@example.com')->blocked_reason)->toBe(BlockReason::HardBounce)
            ->and($upload->fresh()->claimed_at)->not->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([])
            ->and(fn () => app(ImportUploadedContactsAction::class)->execute($upload->uuid, $list, $context, dryRun: true))->toThrow(CampaignsException::class, 'it was already used');
    })->with('databases');

    it('refuses a wrong list or upload before it uses the upload up', function (): void {
        $upload = contactsUpload("email\nann@example.com\n");
        $import = fn (?CampaignList $list, string $id = '') => app(ImportUploadedContactsAction::class)->execute($id === '' ? $upload->uuid : $id, $list, new ConsentContext('expo'), dryRun: false);

        expect(fn () => $import(CampaignList::factory()->dynamic()->create(['key' => 'members'])))->toThrow(CampaignsException::class, 'follows the app\'s records')
            ->and(fn () => $import(CampaignList::factory()->archived()->create(['key' => 'old'])))->toThrow(CampaignsException::class, 'is archived')
            ->and(fn () => $import(null, app(CreateUploadAction::class)->execute(UploadPurpose::Image)->uuid))->toThrow(CampaignsException::class, 'That upload is for an image, not a list of contacts.')
            ->and($upload->fresh()->claimed_at)->toBeNull()
            ->and(array_filter($import(null)->counts))->toBe(['not_found' => 1]);
    });
});
