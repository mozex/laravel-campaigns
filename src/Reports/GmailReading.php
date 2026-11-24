<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Reports;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Mozex\Campaigns\Postmaster\Postmaster;
use Mozex\Campaigns\Postmaster\PostmasterException;
use Mozex\Campaigns\Postmaster\ServiceAccount;
use Mozex\Campaigns\Support\Docs;
use stdClass;

/**
 * Gmail's numbers for the sending domain, for an agent judging whether a
 * campaign may start, resume or speed up: the user-reported spam rate and
 * DKIM, SPF and DMARC success for each of the DAYS days to yesterday (UTC
 * days, as Gmail counts them, about a day late), the latest known day's
 * spam rate with a warning at Google's lines, and Google's bulk-sender
 * checks, read from Postmaster Tools with the service account in
 * campaigns.postmaster.credentials.
 *
 * Gmail reports a day only when the domain sent it enough mail, so a day
 * with no row, a day whose every rate is 0 (low-volume days come that way,
 * DKIM and SPF included) and a day with rows but no spam rate are unknown,
 * shown as null, never as clean. A warning refuses nothing, as the
 * pre-send checks never block.
 *
 * A reading is kept in campaigns.cache_store for an hour and a failed one
 * for 5 minutes, so Google is asked at most once an hour; the access token
 * isn't kept, and nothing kept or answered holds the key, the signed
 * assertion or the token.
 */
class GmailReading
{
    /** The days read, ending yesterday (UTC). */
    public const DAYS = 14;

    public const CACHE_SECONDS = 3600;

    public const FAILED_CACHE_SECONDS = 300;

    /** The spam rate, in percent, Google asks bulk senders to stay under. */
    public const LIMIT = 0.1;

    /** The spam rate, in percent, Google says a bulk sender must never reach. */
    public const STOP = 0.3;

    /**
     * The most of Google's requirements shown per domain (Google names 11),
     * so the overview stays under the answer limit.
     */
    public const REQUIREMENTS = 12;

    /**
     * The metrics read, by the name each day shows them under.
     *
     * @var array<string, array{metric: string, filter?: string}>
     */
    public const METRICS = [
        'spam_rate' => ['metric' => 'SPAM_RATE'],
        'dkim_success_rate' => ['metric' => 'AUTH_SUCCESS_RATE', 'filter' => 'auth_type = "dkim"'],
        'spf_success_rate' => ['metric' => 'AUTH_SUCCESS_RATE', 'filter' => 'auth_type = "spf"'],
        'dmarc_success_rate' => ['metric' => 'AUTH_SUCCESS_RATE', 'filter' => 'auth_type = "dmarc"'],
    ];

    /**
     * The reading for a domain, from the cache when one is kept.
     *
     * @return array<string, mixed>
     */
    public static function for(string $domain): array
    {
        $credentials = config('campaigns.postmaster.credentials');

        if (! is_string($credentials) || trim($credentials) === '') {
            return static::unread('Gmail\'s spam rate isn\'t read: campaigns.postmaster.credentials (CAMPAIGNS_POSTMASTER_CREDENTIALS) holds no Google service account key. With one, the overview and the start, resume and speed-up answers show the domain\'s spam rate from Postmaster Tools.'.Docs::see(Docs::FEEDBACK));
        }

        $key = 'campaigns:gmail-reading:'.sha1(strtolower($domain));
        $cached = rescue(fn (): mixed => static::cache()->get($key));

        if (is_array($cached) && is_bool($cached['read'] ?? null)) {
            return static::shown($cached);
        }

        try {
            $reading = static::read($domain, ServiceAccount::fromCredentials($credentials));
            $seconds = static::CACHE_SECONDS;
        } catch (PostmasterException $exception) {
            $reading = static::unread("Gmail's spam rate for {$domain} couldn't be read: {$exception->getMessage()} It is read again after 5 minutes.");
            $seconds = static::FAILED_CACHE_SECONDS;
        }

        rescue(fn (): bool => static::cache()->put($key, $reading, $seconds));

        return static::shown($reading);
    }

    /**
     * The reading as answered: an empty map of requirements as an object,
     * as the schema says. The cache holds only arrays, since a store that
     * refuses to unserialize classes (cache.serializable_classes) would hand
     * an object back broken.
     *
     * @param  array<mixed>  $reading
     * @return array<string, mixed>
     */
    protected static function shown(array $reading): array
    {
        foreach (['registrable_domain', 'subdomain'] as $part) {
            if (is_array($reading['compliance'][$part] ?? null) && ($reading['compliance'][$part]['requirements'] ?? null) === []) {
                $reading['compliance'][$part]['requirements'] = new stdClass;
            }
        }

        /** @var array<string, mixed> */
        return $reading;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PostmasterException
     */
    protected static function read(string $domain, ServiceAccount $account): array
    {
        $to = now()->utc()->subDay()->startOfDay();
        $from = $to->copy()->subDays(static::DAYS - 1);
        // A domain Postmaster Tools can't read is refused before signing in.
        $domain = Postmaster::domain($domain);
        $postmaster = new Postmaster($account);
        $token = $account->accessToken();

        $days = static::days($postmaster->domainStats($token, $domain, $from, $to, static::METRICS), $from, $to);
        $compliance = $postmaster->complianceStatus($token, $domain);

        $latest = null;

        foreach ($days as $day) {
            if ($day['spam_rate'] !== null) {
                $latest = $day;
            }
        }

        return [
            'read' => true,
            'read_at' => now()->toIso8601String(),
            'spam_rate' => $latest['spam_rate'] ?? null,
            'spam_rate_date' => $latest['date'] ?? null,
            'warning' => static::warning($domain, $latest),
            'note' => $latest === null
                ? "Gmail reported no spam rate for {$domain} in the ".static::DAYS." days to {$to->toDateString()}: it reports a day only when the domain sent it enough mail, so the rate is unknown, never clean."
                : null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'compliance' => [
                'registrable_domain' => static::compliance($compliance['complianceData'] ?? null),
                'subdomain' => static::compliance($compliance['subdomainComplianceData'] ?? null),
            ],
        ];
    }

    /**
     * Every day of the window, oldest first, each rate in percent or null
     * when Gmail's value for it is unknown.
     *
     * @param  list<array{name: string, date: string, value: ?float}>  $stats
     * @return list<array{date: string, spam_rate: ?float, dkim_success_rate: ?float, spf_success_rate: ?float, dmarc_success_rate: ?float}>
     */
    protected static function days(array $stats, CarbonInterface $from, CarbonInterface $to): array
    {
        $values = [];

        foreach ($stats as $stat) {
            if ($stat['value'] !== null) {
                $values[$stat['date']][$stat['name']] = $stat['value'];
            }
        }

        $days = [];

        // Immutable whatever date class the app uses (Date::use()), so the
        // step is always assigned.
        for ($day = $from->toImmutable(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $known = $values[$date] ?? [];

            // A day whose every rate is 0 is one Gmail filled with zeros
            // for want of mail, DKIM and SPF included: unknown, like no row.
            if ($known !== [] && max($known) === 0.0) {
                $known = [];
            }

            $row = ['date' => $date];

            foreach (array_keys(static::METRICS) as $name) {
                $row[$name] = isset($known[$name]) ? round($known[$name] * 100, 4) : null;
            }

            /** @var array{date: string, spam_rate: ?float, dkim_success_rate: ?float, spf_success_rate: ?float, dmarc_success_rate: ?float} $row */
            $days[] = $row;
        }

        return $days;
    }

    /**
     * The warning for the latest known day's spam rate at or above Google's
     * lines, decided on the rate as shown.
     *
     * @param  ?array{date: string, spam_rate: ?float}  $latest
     */
    protected static function warning(string $domain, ?array $latest): ?string
    {
        $rate = $latest['spam_rate'] ?? null;

        if ($latest === null || $rate === null || $rate < static::LIMIT) {
            return null;
        }

        $said = "Gmail's spam rate for {$domain} was {$rate}% on {$latest['date']}";

        return $rate >= static::STOP
            ? "{$said}, at or above ".static::STOP.'%, Gmail\'s stop line: Google says a bulk sender must never reach it.'
            : "{$said}, at or above the ".static::LIMIT.'% Google asks bulk senders to stay under.';
    }

    /**
     * One domain's checks, flattened: each requirement's state, and the
     * verdicts on one-click unsubscribe, honouring unsubscribes and
     * deliverability with their reasons. Only Google's codes are shown.
     *
     * @return ?array{domain: ?string, requirements: array<string, ?string>, one_click_unsubscribe: ?array{status: ?string, reason: ?string}, honor_unsubscribe: ?array{status: ?string, reason: ?string}, deliverability: ?array{status: ?string, reason: ?string}}
     */
    protected static function compliance(mixed $data): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        $requirements = [];
        $rows = is_array($data['rowData'] ?? null) ? $data['rowData'] : [];

        foreach ($rows as $row) {
            $name = PostmasterException::code(data_get($row, 'requirement'));

            if ($name !== null && count($requirements) < static::REQUIREMENTS) {
                $requirements[$name] = PostmasterException::code(data_get($row, 'status.status'));
            }
        }

        $domain = $data['domainId'] ?? null;

        return [
            'domain' => is_string($domain) && preg_match('/^[a-z0-9][a-z0-9.-]{0,252}$/iD', $domain) === 1 ? strtolower($domain) : null,
            'requirements' => $requirements,
            'one_click_unsubscribe' => static::verdict($data['oneClickUnsubscribeVerdict'] ?? null, 'status'),
            'honor_unsubscribe' => static::verdict($data['honorUnsubscribeVerdict'] ?? null, 'status'),
            // The deliverability verdict (a Developer Preview) names its
            // state "state" where the others say "status".
            'deliverability' => static::verdict($data['deliverabilityStatusVerdict'] ?? null, 'state'),
        ];
    }

    /**
     * @return ?array{status: ?string, reason: ?string}
     */
    protected static function verdict(mixed $verdict, string $field): ?array
    {
        if (! is_array($verdict)) {
            return null;
        }

        return [
            'status' => PostmasterException::code(data_get($verdict, $field.'.status')),
            'reason' => PostmasterException::code($verdict['reason'] ?? null),
        ];
    }

    /**
     * The reading's shape when Google wasn't asked or didn't answer.
     *
     * @return array<string, mixed>
     */
    protected static function unread(string $note): array
    {
        return [
            'read' => false,
            'read_at' => null,
            'spam_rate' => null,
            'spam_rate_date' => null,
            'warning' => null,
            'note' => $note,
            'from' => null,
            'to' => null,
            'days' => [],
            'compliance' => null,
        ];
    }

    protected static function cache(): Repository
    {
        $store = config('campaigns.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
