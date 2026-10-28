<?php

declare(strict_types=1);

use Mozex\Campaigns\Enums\EngagementTier;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Support\Engagement;
use Mozex\Campaigns\Tests\Fixtures\Member;

/**
 * What the database says about one subscriber: its tier, whether it is sunset
 * and whether it is mailable, each read through the queries.
 *
 * @return array{0: list<EngagementTier>, 1: bool, 2: bool}
 */
function engagementInSql(Subscriber $subscriber): array
{
    $tiers = array_values(array_filter(
        EngagementTier::cases(),
        fn (EngagementTier $tier): bool => Engagement::whereTier(Subscriber::query()->whereKey($subscriber->id), $tier)->exists(),
    ));

    $sunset = Engagement::whereSunset(Subscriber::query()->whereKey($subscriber->id))->exists();

    // Never both, never neither.
    expect(Engagement::whereSunset(Subscriber::query()->whereKey($subscriber->id), false)->exists())->toBe(! $sunset);

    return [$tiers, $sunset, Subscriber::query()->whereKey($subscriber->id)->mailable()->exists()];
}

it('puts every subscriber in one tier and decides the sunset alike in PHP and in the database', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();

    $year = fn (int $extraSeconds = 0) => now()->subMonthsNoOverflow(12)->subSeconds($extraSeconds);

    $cases = [
        'never engaged, never mailed' => [[], EngagementTier::Silent, false],
        'opened exactly at the engaged window' => [['last_opened_at' => now()->subDays(90)], EngagementTier::Engaged, false],
        'opened a second before it' => [['last_opened_at' => now()->subDays(90)->subSecond()], EngagementTier::Active, false],
        'clicked exactly at the active window' => [['last_clicked_at' => now()->subDays(365)], EngagementTier::Active, false],
        'clicked a second before it' => [['last_clicked_at' => now()->subDays(365)->subSecond()], EngagementTier::Silent, false],
        'active in the app yesterday, nothing else' => [['last_active_at' => now()->subDay()], EngagementTier::Engaged, false],
        'one old date, one recent' => [['last_opened_at' => now()->subYears(3), 'last_active_at' => now()->subDays(5)], EngagementTier::Engaged, false],
        'mailed exactly a year ago, never engaged' => [['first_mailed_at' => $year()], EngagementTier::Silent, false],
        'mailed a year and a second ago, never engaged' => [['first_mailed_at' => $year(1)], EngagementTier::Silent, true],
        'mailed two years ago, opened a year and a second ago' => [['first_mailed_at' => now()->subYears(2), 'last_opened_at' => $year(1)], EngagementTier::Silent, true],
        'mailed two years ago, opened eleven months ago' => [['first_mailed_at' => now()->subYears(2), 'last_opened_at' => now()->subMonths(11)], EngagementTier::Active, false],
        'mailed a month ago, last opened three years ago' => [['first_mailed_at' => now()->subMonth(), 'last_opened_at' => now()->subYears(3)], EngagementTier::Silent, false],
    ];

    foreach ($cases as $name => [$dates, $tier, $sunset]) {
        $subscriber = Subscriber::factory()->create($dates)->fresh();

        expect($subscriber?->tier())->toBe($tier, $name)
            ->and($subscriber?->isSunset())->toBe($sunset, $name)
            ->and($subscriber?->isMailable())->toBe(! $sunset, $name)
            ->and(engagementInSql($subscriber))->toBe([[$tier], $sunset, ! $sunset], $name);
    }
})->with('databases');

it('ends a sunset the moment the person does something in the app', function (): void {
    $this->freezeSecond();
    $member = Member::query()->create(['email' => 'jane@example.com']);
    $subscriber = Subscriber::findFor($member);
    $subscriber?->forceFill(['first_mailed_at' => now()->subYears(2), 'last_opened_at' => now()->subYears(2)])->save();

    $before = [$subscriber?->isSunset(), engagementInSql($subscriber)];
    Campaigns::recordActivity($member);
    $subscriber = $subscriber?->fresh();

    expect($before)->toBe([true, [[EngagementTier::Silent], true, false]])
        ->and($subscriber?->isSunset())->toBeFalse()
        ->and($subscriber?->tier())->toBe(EngagementTier::Engaged)
        ->and(engagementInSql($subscriber))->toBe([[EngagementTier::Engaged], false, true]);
});

it('takes its windows from the config, and never sunsets anyone without a sunset period', function (): void {
    $this->freezeSecond();
    config()->set('campaigns.engagement', ['engaged_days' => 7, 'active_days' => 30, 'sunset_months' => null]);
    $subscriber = Subscriber::factory()->create([
        'last_clicked_at' => now()->subDays(8),
        'first_mailed_at' => now()->subYears(5),
    ])->fresh();
    $silent = Subscriber::factory()->create(['last_clicked_at' => now()->subDays(31), 'first_mailed_at' => now()->subYears(5)])->fresh();

    expect($subscriber?->tier())->toBe(EngagementTier::Active)
        ->and(engagementInSql($subscriber))->toBe([[EngagementTier::Active], false, true])
        ->and($silent?->tier())->toBe(EngagementTier::Silent)
        ->and($silent?->isSunset())->toBeFalse()
        ->and(engagementInSql($silent))->toBe([[EngagementTier::Silent], false, true]);
});

it('never mails an opted-out or blocked address, whatever its engagement', function (): void {
    $optedOut = Subscriber::factory()->optedOut()->create(['last_clicked_at' => now()]);
    $blocked = Subscriber::factory()->blocked()->create(['last_clicked_at' => now()]);
    $reader = Subscriber::factory()->create(['last_clicked_at' => now()]);

    expect([$optedOut->isMailable(), $blocked->isMailable(), $reader->isMailable()])->toBe([false, false, true])
        ->and(Subscriber::query()->mailable()->pluck('id')->all())->toBe([$reader->id]);
});
