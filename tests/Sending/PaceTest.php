<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Mozex\Campaigns\Sending\Pace;

it('grows each day\'s volume from the first, up to the cap, without overflowing', function (array $pace, int $day, int $volume): void {
    expect(Pace::dayVolume(Pace::resolve($pace), $day))->toBe($volume);
})->with([
    'the first day' => [['first_day' => 100, 'daily_growth' => 20], 1, 100],
    'the second' => [['first_day' => 100, 'daily_growth' => 20], 2, 120],
    'the third' => [['first_day' => 100, 'daily_growth' => 20], 3, 144],
    'no growth' => [['first_day' => 100, 'daily_growth' => 0], 30, 100],
    'capped' => [['first_day' => 100, 'daily_growth' => 20, 'daily_cap' => 130], 3, 130],
    'a growth that would overflow' => [['first_day' => 10_000_000, 'daily_growth' => 1000, 'daily_cap' => null], 400, 100_000_000],
]);

it('sends only within its hours, in its own timezone, across midnight too', function (?array $hours, string $timezone, string $utc, bool $sends): void {
    $pace = Pace::resolve(['sending_hours' => $hours, 'timezone' => $timezone]);

    expect(Pace::isSendingTime($pace, Carbon::parse("2026-03-02 {$utc}")))->toBe($sends);
})->with([
    'any hour' => [null, 'UTC', '03:00', true],
    'before the window' => [['09:00', '18:00'], 'UTC', '08:59', false],
    'its first minute' => [['09:00', '18:00'], 'UTC', '09:00', true],
    'its last minute' => [['09:00', '18:00'], 'UTC', '17:59', true],
    'its end' => [['09:00', '18:00'], 'UTC', '18:00', false],
    'past midnight, late' => [['22:00', '06:00'], 'UTC', '23:30', true],
    'past midnight, early' => [['22:00', '06:00'], 'UTC', '05:59', true],
    'past midnight, its end' => [['22:00', '06:00'], 'UTC', '06:00', false],
    'past midnight, midday' => [['22:00', '06:00'], 'UTC', '12:00', false],
    'in Tokyo, inside' => [['09:00', '18:00'], 'Asia/Tokyo', '00:30', true],
    'in Tokyo, outside' => [['09:00', '18:00'], 'Asia/Tokyo', '10:00', false],
]);
