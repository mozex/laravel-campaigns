<?php

declare(strict_types=1);

use Mozex\Campaigns\Audiences\SubscriberFilters;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Enums\ListKind;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Models\Subscription;

/**
 * The emails of the subscribers the filters keep, sorted.
 *
 * @param  array<string, mixed>  $filters
 * @return list<string>
 */
function filtered(array $filters): array
{
    return SubscriberFilters::fromArray($filters)
        ->apply(Subscriber::query())
        ->orderBy('email')
        ->pluck('email')
        ->all();
}

it('narrows by every filter, one filter\'s values with OR and the filters with AND', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();

    $news = CampaignList::factory()->create(['key' => 'news']);
    $accounts = CampaignList::factory()->create(['key' => 'accounts', 'kind' => ListKind::Dynamic]);

    $pro = Subscriber::factory()->create([
        'email' => 'pro@example.com', 'locale' => 'en', 'country' => 'US',
        'custom_fields' => ['plan' => 'pro', 'seats' => 5, 'consented' => true],
        'last_clicked_at' => now()->subDay(), 'created_at' => now()->subDays(10),
    ]);
    $waiting = Subscriber::factory()->create([
        'email' => 'waiting@example.com', 'locale' => 'es', 'country' => null,
        'custom_fields' => ['plan' => 'free', 'seats' => 50, 'consented' => false],
        'last_opened_at' => now()->subDays(200),
    ]);
    $leaver = Subscriber::factory()->create([
        'email' => 'leaver@example.com', 'locale' => null, 'country' => 'DE',
        'opted_out_at' => now()->subDays(2),
    ]);
    $bounced = Subscriber::factory()->blocked()->create([
        'email' => 'bounced@example.com', 'locale' => 'tr', 'country' => 'US',
        'first_mailed_at' => now()->subYears(2),
    ]);

    Subscription::factory()->for($pro)->for($news, 'list')->create();
    Subscription::factory()->for($waiting)->for($news, 'list')->pending()->create();
    Subscription::factory()->for($leaver)->for($accounts, 'list')->create();
    // Off the dynamic list while its record can't be on it, so not on it.
    Subscription::factory()->for($bounced)->for($accounts, 'list')->unsubscribed()->create();

    $everyone = ['bounced@example.com', 'leaver@example.com', 'pro@example.com', 'waiting@example.com'];

    expect(filtered([]))->toBe($everyone)
        ->and(filtered(['lists' => ['news']]))->toBe(['pro@example.com'])
        ->and(filtered(['lists' => ['news'], 'list_status' => 'pending']))->toBe(['waiting@example.com'])
        ->and(filtered(['lists' => ['news', 'accounts']]))->toBe(['leaver@example.com', 'pro@example.com'])
        ->and(filtered(['lists' => ['accounts'], 'list_status' => 'unsubscribed']))->toBe(['bounced@example.com'])
        ->and(filtered(['locales' => ['en', null]]))->toBe(['leaver@example.com', 'pro@example.com'])
        ->and(filtered(['countries' => ['us']]))->toBe(['bounced@example.com', 'pro@example.com'])
        ->and(filtered(['countries' => [null]]))->toBe(['waiting@example.com'])
        ->and(filtered(['tiers' => ['engaged', 'active']]))->toBe(['pro@example.com', 'waiting@example.com'])
        ->and(filtered(['tiers' => ['silent']]))->toBe(['bounced@example.com', 'leaver@example.com'])
        ->and(filtered(['opted_out' => true]))->toBe(['leaver@example.com'])
        ->and(filtered(['blocked' => false]))->toBe(['leaver@example.com', 'pro@example.com', 'waiting@example.com'])
        ->and(filtered(['sunset' => true]))->toBe(['bounced@example.com'])
        ->and(filtered(['custom_fields' => ['plan' => 'pro']]))->toBe(['pro@example.com'])
        ->and(filtered(['custom_fields' => ['seats' => 5]]))->toBe(['pro@example.com'])
        ->and(filtered(['custom_fields' => ['consented' => true]]))->toBe(['pro@example.com'])
        ->and(filtered(['custom_fields' => ['consented' => false]]))->toBe(['waiting@example.com'])
        ->and(filtered(['custom_fields' => ['plan' => null]]))->toBe(['bounced@example.com', 'leaver@example.com'])
        ->and(filtered(['custom_fields' => ['plan' => 'free', 'seats' => 50]]))->toBe(['waiting@example.com'])
        ->and(filtered(['dates' => [['field' => 'created', 'before' => now()->subDays(5)->toIso8601String()]]]))->toBe(['pro@example.com'])
        ->and(filtered(['dates' => [['field' => 'opted_out', 'after' => now()->subDays(3)->toIso8601String(), 'before' => now()->toIso8601String()]]]))->toBe(['leaver@example.com'])
        ->and(filtered(['lists' => ['news', 'accounts'], 'locales' => ['en'], 'tiers' => ['engaged']]))->toBe(['pro@example.com']);
})->with('databases');

it('gives back what it reads, so a saved set of filters picks the same people', function (): void {
    $filters = [
        'lists' => ['news'],
        'locales' => ['en', null],
        'countries' => ['us'],
        'tiers' => ['engaged'],
        'opted_out' => false,
        'custom_fields' => ['plan' => 'pro', 'consented' => true],
        'dates' => [['field' => 'last_clicked', 'after' => '2026-01-01T00:00:00+03:00']],
    ];

    $stored = SubscriberFilters::fromArray($filters)->toArray();

    expect($stored)->toBe([
        'lists' => ['news'],
        'list_status' => 'subscribed',
        'locales' => ['en', null],
        'countries' => ['US'],
        'tiers' => ['engaged'],
        'opted_out' => false,
        'custom_fields' => ['plan' => 'pro', 'consented' => true],
        'dates' => [['field' => 'last_clicked', 'after' => '2025-12-31T21:00:00+00:00', 'before' => null]],
    ])
        ->and(SubscriberFilters::fromArray($stored)->toArray())->toBe($stored)
        ->and(SubscriberFilters::fromArray(['locales' => [], 'custom_fields' => []])->toArray())->toBe([]);
});

it('refuses a filter it can\'t use, naming what to use instead', function (array $filters, string $message): void {
    expect(fn () => SubscriberFilters::fromArray($filters))->toThrow(CampaignsException::class, $message);
})->with([
    'an unknown filter' => [['segment' => 'vip'], 'The filter "segment" can\'t be used: there is no such filter; use lists'],
    'a list status without lists' => [['list_status' => 'pending'], 'it needs lists'],
    'an unknown list status' => [['lists' => ['news'], 'list_status' => 'gone'], 'use pending, subscribed, unsubscribed'],
    'lists that are not a list' => [['lists' => 'news'], 'it takes a list'],
    'a blank list key' => [['lists' => ['']], 'its values are text'],
    'a locale that is not text' => [['locales' => [5]], 'its values are text or null'],
    'a country that is not a code' => [['countries' => ['USA']], 'use two-letter country codes'],
    'an unknown tier' => [['tiers' => ['vip']], 'use engaged, active, silent'],
    'a flag that is not true or false' => [['opted_out' => 'yes'], 'it is true or false'],
    'custom fields as a list' => [['custom_fields' => ['plan']], 'it takes a map of field names to values'],
    'a nested custom field' => [['custom_fields' => ['plan->tier' => 'gold']], 'a field name is letters, digits and underscores'],
    'a custom field named by digits' => [['custom_fields' => ['2024' => 'yes']], 'starting with a letter or an underscore'],
    'a date with a bound it doesn\'t know' => [['dates' => [['field' => 'created', 'after' => '2026-01-01', 'until' => '2026-02-01']]], 'each date has only a field'],
    'a custom field compared with a list' => [['custom_fields' => ['plan' => ['pro']]], 'a value is text, a whole number, true, false or null'],
    'a date on no field' => [['dates' => [['after' => '2026-01-01']]], 'each date names a field'],
    'a date with no bound' => [['dates' => [['field' => 'created']]], 'each date has an "after", a "before" or both'],
    'a date that can\'t be read' => [['dates' => [['field' => 'created', 'after' => 'someday soon']]], 'a date can\'t be read'],
]);

it('refuses a list that doesn\'t exist, rather than finding nobody', function (): void {
    CampaignList::factory()->create(['key' => 'news']);

    expect(fn () => filtered(['lists' => ['news', 'nwes', 'old']]))
        ->toThrow(CampaignsException::class, 'No list has the key "nwes", "old".');
});
