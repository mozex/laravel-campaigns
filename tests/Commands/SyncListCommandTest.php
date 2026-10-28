<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Mozex\Campaigns\Actions\OptOutAction;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;
use Mozex\Campaigns\Support\ConsentContext;
use Mozex\Campaigns\Tests\Fixtures\Member;

/**
 * Members as they were before the app installed the package: made a day
 * ago, with no observer to put them on a list.
 *
 * @param  array<string, mixed>  $attributes
 */
function existingMember(array $attributes): Member
{
    return Member::withoutEvents(fn (): Member => Member::query()->create([...$attributes, 'created_at' => now()->subDay()]));
}

function syncList(string ...$options): string
{
    Artisan::call('campaigns:sync-list', ['list' => 'members', ...array_fill_keys($options, true)]);

    return Artisan::output();
}

it('brings existing records onto the list as legacy, writing nothing on a dry run', function (string $driver): void {
    useDatabase($driver);

    existingMember(['email' => 'jane@example.com', 'name' => 'Jane Doe']);
    existingMember(['email' => ' Contact@Example.com ']);
    existingMember(['email' => 'banned@example.com', 'banned_at' => now()]);
    $broken = existingMember(['email' => 'not an address']);
    Subscriber::factory()->optedOut()->create(['email' => 'contact@example.com']);

    $dryRun = syncList();
    $written = [Subscriber::query()->count(), CampaignList::query()->count(), ConsentRecord::query()->count()];

    $forced = syncList('--force');

    expect($written)->toBe([1, 0, 0])
        ->and($dryRun)
        ->toContain('Dry run: nothing was written.')
        ->toMatch('/Checked\s*\|\s*4\s/')
        ->toMatch('/New subscribers\s*\|\s*1\s/')
        ->toMatch('/Linked to an existing address\s*\|\s*1\s/')
        ->toMatch('/Added to the list\s*\|\s*2\s/')
        ->toMatch('/Unchanged\s*\|\s*1\s/')
        ->toMatch('/opted out or blocked\)\s*\|\s*1\s/')
        ->toContain("Record {$broken->id}: no valid email address")
        ->and($forced)
        ->not->toContain('Dry run')
        ->toMatch('/Added to the list\s*\|\s*2\s/')
        ->and(Subscriber::query()->orderBy('email')->pluck('email')->all())->toBe(['contact@example.com', 'jane@example.com'])
        ->and(Subscription::query()->pluck('basis')->unique()->all())->toBe([ConsentBasis::Legacy])
        ->and(ConsentRecord::query()->pluck('source')->all())->toBe(['sync', 'sync'])
        ->and(Subscriber::findByEmail('contact@example.com')?->opted_out_at)->not->toBeNull();
})->with('databases');

it('changes nothing on a second run, and syncs one record with --id', function (): void {
    $jane = existingMember(['email' => 'jane@example.com']);
    existingMember(['email' => 'john@example.com']);
    syncList('--force');
    app(OptOutAction::class)->execute(Subscriber::findFor($jane), new ConsentContext('unsubscribe-page'));
    $jane->update(['banned_at' => now()]);

    Artisan::call('campaigns:sync-list', ['list' => 'members', '--id' => (string) $jane->id, '--force' => true]);
    $one = Artisan::output();
    $again = syncList('--force');

    expect($one)
        ->toMatch('/Checked\s*\|\s*1\s/')
        ->and($again)
        ->toMatch('/Checked\s*\|\s*2\s/')
        ->toMatch('/Unchanged\s*\|\s*2\s/')
        ->and(Subscription::query()->where('subscriber_id', Subscriber::findFor($jane)?->id)->sole()->status)->toBe(SubscriptionStatus::Unsubscribed)
        ->and(Subscriber::findFor($jane)?->opted_out_at)->not->toBeNull()
        ->and(ConsentRecord::query()->where('action', ConsentAction::OptOut)->count())->toBe(1);
});

it('refuses a list without a source and a record that does not exist', function (): void {
    $missingList = Artisan::call('campaigns:sync-list', ['list' => 'missing']);
    $missingListOutput = Artisan::output();
    $missingRecord = Artisan::call('campaigns:sync-list', ['list' => 'members', '--id' => '999']);

    expect($missingList)->toBe(1)
        ->and($missingListOutput)->toContain('No list source is configured for the list "missing"')
        ->and($missingRecord)->toBe(1)
        ->and(Artisan::output())->toContain('No record has the key 999.');
});
