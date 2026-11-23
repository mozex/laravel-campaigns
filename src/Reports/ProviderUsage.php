<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReadsUsage;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Usage;
use Mozex\Campaigns\Support\ShownText;
use Throwable;

/**
 * What the account behind the active driver has sent against its
 * allowance (ReadsUsage), for an agent judging whether a campaign fits: a
 * plan's month, whose overage the provider bills, or a rolling 24-hour
 * quota, past which it holds mail back. A driver without the ability, or a
 * read that fails, answers "not read" with why, never a count of 0.
 *
 * A reading is kept in campaigns.cache_store for an hour and a failed one
 * for 5 minutes, keyed by the driver and the UTC day, so a month's first
 * day never shows the month before. The start, resume and speed-up answers
 * warn from it (warning()).
 */
class ProviderUsage
{
    public const CACHE_SECONDS = 3600;

    public const FAILED_CACHE_SECONDS = 300;

    /** The most of the driver's notes shown, so the answers stay under the limit. */
    public const NOTE_LENGTH = 1000;

    /**
     * What the provider bills or holds back past the allowance, by whether
     * it holds mail back.
     */
    public const PAST_ALLOWANCE = ['billed', 'held_back'];

    /**
     * The active driver's usage as answered, from the cache when one is
     * kept.
     *
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $drivers = app(DriverManager::class);
        $name = $drivers->getDefaultDriver();

        try {
            $driver = $drivers->active();
        } catch (CampaignsException $exception) {
            return static::unread($exception->getMessage());
        }

        if (! $driver instanceof ReadsUsage) {
            return static::unread(CampaignsException::driverLacks($name, 'reads no usage, so what the account sent against its plan or quota isn\'t known here')->getMessage());
        }

        $key = 'campaigns:provider-usage:'.sha1($name).':'.now()->utc()->toDateString();
        $cached = rescue(fn (): mixed => static::cache()->get($key));

        if (is_array($cached) && is_bool($cached['read'] ?? null)) {
            /** @var array<string, mixed> */
            return $cached;
        }

        try {
            $reading = static::shown($driver->usage());
            $seconds = static::CACHE_SECONDS;
        } catch (CampaignsException $exception) {
            $reading = static::unread("The provider's usage couldn't be read: {$exception->getMessage()} It is read again after 5 minutes.");
            $seconds = static::FAILED_CACHE_SECONDS;
        } catch (Throwable $exception) {
            // A driver an app registered may throw anything; its message may
            // say more than an agent should read.
            report($exception);
            $reading = static::unread('The provider\'s usage couldn\'t be read ('.class_basename($exception).'). It is read again after 5 minutes.');
            $seconds = static::FAILED_CACHE_SECONDS;
        }

        rescue(fn (): bool => static::cache()->put($key, $reading, $seconds));

        return $reading;
    }

    /**
     * What a reading says about a campaign with this many emails still to
     * send: a plan's month passed (billed), or a quota passed by what the
     * campaign may send in a day (the lesser of those emails and its daily
     * cap), which the provider holds back. Null when nothing passes, the
     * reading has no allowance, or the count is unknown.
     *
     * @param  array<string, mixed>  $reading  as read() answers it
     */
    public static function warning(array $reading, ?int $unsent, ?int $dailyCap): ?string
    {
        $sent = $reading['sent'] ?? null;
        $allowance = $reading['allowance'] ?? null;

        if ($unsent === null || $unsent < 1 || ! is_int($sent) || ! is_int($allowance)) {
            return null;
        }

        if (($reading['period'] ?? null) === Usage::MONTH) {
            // Counted from what is left, so no sum can overflow.
            $left = $allowance - $sent;

            if ($unsent <= $left) {
                return null;
            }

            $over = $left < 0 ? $unsent : $unsent - $left;
            $verb = ($reading['past_allowance'] ?? null) === 'held_back' ? 'holds back' : 'bills as overage';

            return "The account has sent {$sent} of the {$allowance} emails its plan includes this month: with this campaign's {$unsent} still to send, it passes them by {$over}, which the provider {$verb} if they all go this month.";
        }

        $day = $dailyCap === null ? $unsent : min($unsent, $dailyCap);
        $left = max(0, $allowance - $sent);

        if ($day <= $left) {
            return null;
        }

        $verb = ($reading['past_allowance'] ?? null) === 'billed' ? 'bills as extra' : 'holds back, so its sending slows';
        $what = $dailyCap !== null && $dailyCap < $unsent ? "its daily cap lets it send {$day} in a day" : "its {$unsent} emails still to send could go within a day";

        return "The account may send {$left} more of its {$allowance} in 24 hours (it sent {$sent} in the last 24), while {$what}: what passes the quota the provider {$verb}.";
    }

    /**
     * A driver's usage as answered: a count below 0 as 0, an allowance
     * below 0 as none.
     *
     * @return array<string, mixed>
     *
     * @throws CampaignsException for a period the package doesn't know
     */
    protected static function shown(Usage $usage): array
    {
        if (! in_array($usage->period, [Usage::MONTH, Usage::DAY], true)) {
            throw CampaignsException::providerUnreadable('The driver gave a period the package doesn\'t know.');
        }

        $sent = max(0, $usage->sent);
        $allowance = $usage->allowance === null || $usage->allowance < 0 ? null : $usage->allowance;
        $notes = array_values(array_filter($usage->notes, fn (string $note): bool => trim($note) !== ''));

        return [
            'read' => true,
            'read_at' => now()->toIso8601String(),
            'period' => $usage->period,
            'from' => $usage->from->toIso8601String(),
            'to' => $usage->to->toIso8601String(),
            'sent' => $sent,
            'allowance' => $allowance,
            'left' => $allowance === null ? null : max(0, $allowance - $sent),
            'past_allowance' => $allowance === null ? null : static::PAST_ALLOWANCE[$usage->enforced ? 1 : 0],
            'per_second' => $usage->perSecond !== null && $usage->perSecond > 0 ? $usage->perSecond : null,
            'note' => $notes === [] ? null : ShownText::of(implode(' ', $notes), static::NOTE_LENGTH),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function unread(string $note): array
    {
        return [
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
            'note' => ShownText::of($note, static::NOTE_LENGTH),
        ];
    }

    protected static function cache(): Repository
    {
        $store = config('campaigns.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
