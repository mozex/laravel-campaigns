<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Sending;

use Carbon\CarbonInterface;
use DateTimeZone;
use Mozex\Campaigns\CampaignsException;

/**
 * How fast a campaign sends. A campaign stores only the values it was given
 * (campaigns.pace), and campaigns.pace in the config fills in the rest when
 * it is read, so a value left unset follows the config. A null daily_cap or
 * sending_hours given on purpose means none.
 */
class Pace
{
    public const KEYS = ['first_day', 'daily_growth', 'daily_cap', 'per_minute', 'sending_hours', 'timezone'];

    /**
     * The values given, checked and cleaned, as the campaign stores them.
     *
     * @param  array<array-key, mixed>  $pace
     * @return array<string, mixed>
     *
     * @throws CampaignsException naming the first value it can't use
     */
    public static function validate(array $pace): array
    {
        $clean = [];

        foreach ($pace as $key => $value) {
            $clean[(string) $key] = match ($key) {
                'first_day' => static::whole($key, $value, 1, 10_000_000),
                'per_minute' => static::whole($key, $value, 1, 10_000),
                'daily_cap' => $value === null ? null : static::whole($key, $value, 1, 100_000_000),
                'daily_growth' => static::growth($value),
                'sending_hours' => $value === null ? null : static::hours($value),
                'timezone' => static::timezone($value),
                default => throw CampaignsException::invalidPace((string) $key, 'there is no such setting; use '.implode(', ', static::KEYS)),
            };
        }

        return $clean;
    }

    /**
     * Every value, the campaign's own over the config's.
     *
     * @param  ?array<string, mixed>  $stored
     * @return array{first_day: int, daily_growth: int|float, daily_cap: ?int, per_minute: int, sending_hours: ?array{0: string, 1: string}, timezone: string}
     */
    public static function resolve(?array $stored): array
    {
        $config = (array) config('campaigns.pace', []);
        $value = fn (string $key, mixed $default): mixed => array_key_exists($key, $stored ?? [])
            ? $stored[$key]
            : ($config[$key] ?? $default);

        $growth = $value('daily_growth', 20);
        $cap = $value('daily_cap', null);

        /** @var ?array{0: string, 1: string} $hours */
        $hours = $value('sending_hours', null);

        return [
            'first_day' => (int) $value('first_day', 100),
            'daily_growth' => is_int($growth) ? $growth : (float) $growth,
            'daily_cap' => $cap === null ? null : (int) $cap,
            'per_minute' => (int) $value('per_minute', 10),
            'sending_hours' => $hours,
            'timezone' => (string) $value('timezone', 'UTC'),
        ];
    }

    /**
     * Whether going from one pace to another sends faster in any way: a
     * higher number, a daily cap lifted, or sending hours that reach a time
     * the old ones didn't (a new timezone counts, unless both send all day).
     *
     * @param  array{first_day: int, daily_growth: int|float, daily_cap: ?int, per_minute: int, sending_hours: ?array{0: string, 1: string}, timezone: string}  $from
     * @param  array{first_day: int, daily_growth: int|float, daily_cap: ?int, per_minute: int, sending_hours: ?array{0: string, 1: string}, timezone: string}  $to
     */
    public static function isFaster(array $from, array $to): bool
    {
        foreach (['first_day', 'daily_growth', 'per_minute'] as $key) {
            if ($to[$key] > $from[$key]) {
                return true;
            }
        }

        if ($from['daily_cap'] !== null && ($to['daily_cap'] === null || $to['daily_cap'] > $from['daily_cap'])) {
            return true;
        }

        if ($from['sending_hours'] === null) {
            return false;
        }

        if ($to['sending_hours'] === null || $to['timezone'] !== $from['timezone']) {
            return true;
        }

        return ! static::within(static::minutes($to['sending_hours']), static::minutes($from['sending_hours']));
    }

    /**
     * Whether a moment falls inside the sending hours, read in the pace's
     * timezone; always, when it has none.
     *
     * @param  array{first_day: int, daily_growth: int|float, daily_cap: ?int, per_minute: int, sending_hours: ?array{0: string, 1: string}, timezone: string}  $pace
     */
    public static function isSendingTime(array $pace, CarbonInterface $at): bool
    {
        if ($pace['sending_hours'] === null) {
            return true;
        }

        $local = $at->copy()->setTimezone($pace['timezone']);
        $minute = $local->hour * 60 + $local->minute;

        return static::within([[$minute, $minute + 1]], static::minutes($pace['sending_hours']));
    }

    /**
     * How many emails a day of the pace may send: the first day's number,
     * grown by daily_growth percent each later day, up to the daily cap (or
     * the largest first_day allowed, so a fast growth can't overflow).
     *
     * @param  array{first_day: int, daily_growth: int|float, daily_cap: ?int, per_minute: int, sending_hours: ?array{0: string, 1: string}, timezone: string}  $pace
     * @param  int  $day  counted from 1
     */
    public static function dayVolume(array $pace, int $day): int
    {
        $volume = $pace['first_day'] * (1 + $pace['daily_growth'] / 100) ** max(0, $day - 1);

        // Rounded first, so 119.99999 from the float maths is still 120.
        return (int) min($pace['daily_cap'] ?? 100_000_000, floor(round($volume, 6)));
    }

    protected static function whole(string $key, mixed $value, int $min, int $max): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw CampaignsException::invalidPace($key, "it is a whole number from {$min} to {$max}");
        }

        return $value;
    }

    protected static function growth(mixed $value): int|float
    {
        if (! is_int($value) && ! is_float($value) || $value < 0 || $value > 1000) {
            throw CampaignsException::invalidPace('daily_growth', 'it is a percentage from 0 to 1000');
        }

        return $value;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected static function hours(mixed $value): array
    {
        $valid = is_array($value) && array_is_list($value) && count($value) === 2
            && is_string($value[0]) && is_string($value[1])
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d\z/', $value[0]) === 1
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d\z/', $value[1]) === 1
            && $value[0] !== $value[1];

        if (! $valid) {
            throw CampaignsException::invalidPace('sending_hours', 'it is a start and an end time, such as ["09:00", "18:00"] (an end before the start runs past midnight), or null for any hour');
        }

        /** @var array{0: string, 1: string} $value */
        return $value;
    }

    protected static function timezone(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw CampaignsException::invalidPace('timezone', 'it is a timezone name, such as UTC or Europe/Paris');
        }

        return $value;
    }

    /**
     * A window as minute ranges of the day, [start, end), split in two when
     * it runs past midnight.
     *
     * @param  array{0: string, 1: string}  $hours
     * @return list<array{0: int, 1: int}>
     */
    protected static function minutes(array $hours): array
    {
        [$start, $end] = array_map(fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2), $hours);

        return $start < $end ? [[$start, $end]] : [[$start, 1440], [0, $end]];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $inner
     * @param  list<array{0: int, 1: int}>  $outer
     */
    protected static function within(array $inner, array $outer): bool
    {
        foreach ($inner as [$start, $end]) {
            $covered = false;

            foreach ($outer as [$outerStart, $outerEnd]) {
                if ($start >= $outerStart && $end <= $outerEnd) {
                    $covered = true;
                }
            }

            if (! $covered) {
                return false;
            }
        }

        return true;
    }
}
