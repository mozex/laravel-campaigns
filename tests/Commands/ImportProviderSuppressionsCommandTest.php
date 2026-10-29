<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\ConsentAction;
use Mozex\Campaigns\Enums\ConsentBasis;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Models\ConsentRecord;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\Suppression;

beforeEach(function (): void {
    sendFromExample();
    config()->set('campaigns.provider', 'fake');

    fakeProvider()->suppress(
        new Suppression('bounced@example.org', SuppressionType::Bounce, Carbon::parse('2025-12-11 01:49:40'), '550 No such mailbox'),
        new Suppression('complained@example.org', SuppressionType::Complaint),
        new Suppression('stranger@example.org', SuppressionType::Bounce),
        new Suppression('unsubscribed@example.org', SuppressionType::Unsubscribe),
        new Suppression('not an address', SuppressionType::Bounce),
    );
});

/**
 * Subscribers for three of the provider's suppressed addresses, made on the
 * database the test runs on.
 */
function suppressedSubscribers(): void
{
    foreach (['bounced@example.org', 'complained@example.org', 'unsubscribed@example.org'] as $email) {
        Subscriber::factory()->create(['email' => $email]);
    }
}

/**
 * @param  array<string, mixed>  $options
 */
function importSuppressions(string $domain, array $options = []): string
{
    Artisan::call('campaigns:import-provider-suppressions', ['domain' => $domain, ...$options]);

    return Artisan::output();
}

it('blocks another domain\'s bounces and spam reports after a dry run, leaving its unsubscribes, and keeps an unknown address only so it is never mailed', function (string $driver): void {
    useDatabase($driver);
    suppressedSubscribers();

    $dryRun = importSuppressions('Example.com');
    $untouched = Subscriber::query()->whereNotNull('blocked_at')->count() + ConsentRecord::query()->count();
    $forced = importSuppressions('example.com', ['--force' => true]);

    $bounced = Subscriber::query()->where('email', 'bounced@example.org')->firstOrFail();
    $record = ConsentRecord::query()->where('subscriber_id', $bounced->id)->sole();

    expect($untouched)->toBe(0)
        ->and($dryRun)
        ->toContain('Dry run: nothing was written.')
        ->toMatch('/Read\s*\|\s*4\s/')
        ->toMatch('/Blocked\s*\|\s*3\s/')
        ->toMatch('/Not imported \(see below\)\s*\|\s*1\s/')
        ->and($forced)->not->toContain('Dry run')
        ->and($bounced)
        ->blocked_reason->toBe(BlockReason::HardBounce)
        ->opted_out_at->toBeNull()
        ->and(Subscriber::query()->where('email', 'complained@example.org')->firstOrFail())
        ->blocked_reason->toBe(BlockReason::Complaint)
        ->opted_out_at->toBeNull()
        ->and(Subscriber::query()->where('email', 'stranger@example.org')->firstOrFail())
        ->blocked_reason->toBe(BlockReason::HardBounce)
        ->name->toBeNull()
        ->and(Subscriber::query()->where('email', 'unsubscribed@example.org')->firstOrFail())
        ->opted_out_at->toBeNull()
        ->blocked_at->toBeNull()
        ->and($record)
        ->action->toBe(ConsentAction::Block)
        ->source->toBe('provider_suppressions_import')
        ->basis->toBe(ConsentBasis::Imported)
        ->and($record->meta)->toMatchArray(['domain' => 'example.com', 'provider_list' => 'bounce', 'listed_at' => '2025-12-11T01:49:40+00:00']);
})->with('databases');

it('opts out the marketing domain\'s own unsubscribes, and tries one address first', function (): void {
    suppressedSubscribers();
    $output = importSuppressions('news.example.com', ['--email' => 'unsubscribed@example.org', '--force' => true]);

    expect($output)->toMatch('/Opted out\s*\|\s*1\s/')
        ->and(Subscriber::query()->where('email', 'unsubscribed@example.org')->firstOrFail()->opted_out_at)->not->toBeNull()
        ->and(Subscriber::query()->whereNotNull('blocked_at')->count())->toBe(0);
});

it('stops when the provider can\'t be read, and refuses an address that isn\'t one before asking it', function (): void {
    suppressedSubscribers();
    fakeProvider()->failReads(CampaignsException::missingMailgunApiKey());

    $this->artisan('campaigns:import-provider-suppressions', ['domain' => 'example.com', '--force' => true])
        ->expectsOutputToContain('CAMPAIGNS_MAILGUN_API_KEY')
        ->assertFailed();

    $this->artisan('campaigns:import-provider-suppressions', ['domain' => 'example.com', '--email' => 'not an address'])
        ->expectsOutputToContain(CampaignsException::invalidEmail()->getMessage())
        ->doesntExpectOutputToContain('CAMPAIGNS_MAILGUN_API_KEY')
        ->assertFailed();

    expect(Subscriber::query()->whereNotNull('blocked_at')->count())->toBe(0);
});
