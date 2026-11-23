<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\Usage;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-30 23:10:00', 'UTC'));
    config([
        'campaigns.driver' => 'fake',
        'cache.stores.campaigns' => ['driver' => 'array'],
        'campaigns.cache_store' => 'campaigns',
    ]);
});

it('keeps a reading an hour in the configured store, and reads again on a new UTC day', function (): void {
    fakeDriver()->used(120, 50_000);
    $first = ProviderUsage::read();

    fakeDriver()->used(130, 50_000);
    $this->travel(40)->minutes();
    $kept = ProviderUsage::read();

    $key = 'campaigns:provider-usage:'.sha1('fake').':2026-11-30';

    expect($first)->toMatchArray(['read' => true, 'read_at' => '2026-11-30T23:10:00+00:00', 'period' => 'month', 'from' => '2026-11-01T00:00:00+00:00', 'sent' => 120, 'left' => 49_880])
        ->and($kept)->toBe($first)
        ->and(Cache::store('campaigns')->get($key))->toBe($first)
        ->and(Cache::store()->get($key))->toBeNull();

    // The month's first day never shows the month before.
    $this->travel(15)->minutes();
    fakeDriver()->used(4, 50_000);

    expect(ProviderUsage::read())->toMatchArray(['sent' => 4, 'from' => '2026-12-01T00:00:00+00:00']);

    $this->travel(61)->minutes();
    fakeDriver()->used(9, 50_000);

    expect(ProviderUsage::read()['sent'])->toBe(9);
});

it('keeps a failed read 5 minutes, saying why, never as a count of 0', function (): void {
    fakeDriver()->failReads(CampaignsException::providerUnreadable('Mailgun answered 401 when its logs, suppressions or metrics were read.'));

    $failed = ProviderUsage::read();
    fakeDriver()->readsWork()->used(7);
    $this->travel(4)->minutes();
    $kept = ProviderUsage::read();
    $this->travel(2)->minutes();

    expect($failed)->toBe([
        'read' => false,
        'read_at' => null,
        'period' => null,
        'from' => null,
        'to' => null,
        'sent' => null,
        'allowance' => null,
        'left' => null,
        'past_allowance' => null,
        'per_second' => null,
        'note' => 'The provider\'s usage couldn\'t be read: Mailgun answered 401 when its logs, suppressions or metrics were read. It is read again after 5 minutes.',
    ])
        ->and($kept)->toBe($failed)
        ->and(ProviderUsage::read())->toMatchArray(['read' => true, 'sent' => 7, 'allowance' => null, 'left' => null, 'past_allowance' => null]);
});

it('shows a driver\'s odd values safely: no count below 0, no allowance below 0, notes joined and cut', function (): void {
    app(DriverManager::class)->swap('fake', (new FakeDriver)->used(-5, -1, Usage::DAY, enforced: true, perSecond: 0.0, notes: ['', 'One.', ' ', 'Two.']));

    expect(ProviderUsage::read())->toMatchArray([
        'period' => 'last_24_hours',
        'from' => '2026-11-29T23:10:00+00:00',
        'sent' => 0,
        'allowance' => null,
        'left' => null,
        'past_allowance' => null,
        'per_second' => null,
        'note' => 'One. Two.',
    ]);

    Cache::store('campaigns')->flush();
    app(DriverManager::class)->swap('fake', (new FakeDriver)->used(1, period: 'week'));

    expect(ProviderUsage::read())->toMatchArray(['read' => false, 'note' => 'The provider\'s usage couldn\'t be read: The driver gave a period the package doesn\'t know. It is read again after 5 minutes.']);
});
