<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Postmaster;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Google Postmaster Tools API v2, read as a service account: a domain's
 * daily statistics (domainStats:query) and its bulk-sender checks
 * (complianceStatus), the two reads the postmaster.traffic.readonly scope
 * allows. Nothing here lists, creates or changes a domain.
 *
 * Each read takes the access token as a SensitiveParameter rather than the
 * client keeping it, so no dump of the client (Laravel's dump() prints real
 * properties) shows it. A failure is a PostmasterException naming the status
 * and Google's codes, never the request (whose header holds the token) or
 * Google's words.
 */
class Postmaster
{
    public const API = 'https://gmailpostmastertools.googleapis.com/v2/';

    /**
     * The pages of statistics followed. A reading asks for 14 days of four
     * metrics, 56 values on one page of 200: more pages than this means the
     * answer isn't what was asked for.
     */
    public const MAX_PAGES = 5;

    protected const PAGE_SIZE = 200;

    public function __construct(protected ServiceAccount $account) {}

    /**
     * A domain's daily values of the metrics asked for, each under the name
     * it was asked by. A rate is a fraction (0.0074 is 0.74%), null when
     * Google gave one that can't be a rate. A day Gmail didn't report has
     * no entry.
     *
     * @param  array<string, array{metric: string, filter?: string}>  $metrics  by name
     * @return list<array{name: string, date: string, value: ?float}>
     *
     * @throws PostmasterException
     */
    public function domainStats(#[SensitiveParameter] string $token, string $domain, CarbonInterface $from, CarbonInterface $to, array $metrics): array
    {
        $domain = static::domain($domain);
        $definitions = [];

        foreach ($metrics as $name => $metric) {
            $definitions[] = ['name' => $name, 'baseMetric' => ['standardMetric' => $metric['metric']], ...(isset($metric['filter']) ? ['filter' => $metric['filter']] : [])];
        }

        $stats = [];
        $pageToken = null;

        for ($page = 0; $page < static::MAX_PAGES; $page++) {
            $answer = $this->request($token, 'POST', "domains/{$domain}/domainStats:query", $domain, [
                'metricDefinitions' => $definitions,
                'timeQuery' => ['dateRanges' => ['dateRanges' => [['start' => static::day($from), 'end' => static::day($to)]]]],
                'aggregationGranularity' => 'DAILY',
                'pageSize' => static::PAGE_SIZE,
                ...($pageToken === null ? [] : ['pageToken' => $pageToken]),
            ]);

            foreach (is_array($answer['domainStats'] ?? null) ? $answer['domainStats'] : [] as $stat) {
                $entry = is_array($stat) ? static::stat($stat, $metrics) : null;

                if ($entry !== null) {
                    $stats[] = $entry;
                }
            }

            $pageToken = $answer['nextPageToken'] ?? null;

            if (! is_string($pageToken) || $pageToken === '') {
                return $stats;
            }
        }

        throw new PostmasterException('Google\'s statistics ran past '.static::MAX_PAGES.' pages.');
    }

    /**
     * Google's bulk-sender checks for a domain, as Google answers them:
     * complianceData for the registrable domain and, when Google has it,
     * subdomainComplianceData for the subdomain itself.
     *
     * @return array<mixed>
     *
     * @throws PostmasterException
     */
    public function complianceStatus(#[SensitiveParameter] string $token, string $domain): array
    {
        $domain = static::domain($domain);

        return $this->request($token, 'GET', "domains/{$domain}/complianceStatus", $domain);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     *
     * @throws PostmasterException
     */
    protected function request(#[SensitiveParameter] string $token, string $method, string $path, string $domain, array $body = []): array
    {
        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(ServiceAccount::TIMEOUT)
                ->timeout(ServiceAccount::TIMEOUT);

            $response = $method === 'POST' ? $request->post(static::API.$path, $body) : $request->get(static::API.$path);
        } catch (Throwable $exception) {
            throw new PostmasterException('Postmaster Tools couldn\'t be reached ('.class_basename($exception).').');
        }

        // Decoded here, never through the response's json(), whose flags an
        // app may set to throw on Google's HTML error pages.
        $answer = json_decode($response->body(), true);

        if ($response->successful()) {
            if (! is_array($answer)) {
                throw new PostmasterException("Postmaster Tools' answer (HTTP {$response->status()}) couldn't be read.");
            }

            return $answer;
        }

        $status = PostmasterException::code(data_get($answer, 'error.status'));
        $reasons = [];

        foreach ((array) data_get($answer, 'error.details', []) as $detail) {
            $reason = PostmasterException::code(is_array($detail) ? ($detail['reason'] ?? null) : null);

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        $codes = array_slice(array_values(array_unique(array_filter([$status, ...$reasons]))), 0, 3);

        throw new PostmasterException("Postmaster Tools answered HTTP {$response->status()}".($codes === [] ? '' : ' ('.implode(', ', $codes).')').'.'.$this->hint($response->status(), $reasons, $domain));
    }

    /**
     * @param  list<string>  $reasons
     */
    protected function hint(int $status, array $reasons, string $domain): string
    {
        return match (true) {
            in_array('SERVICE_DISABLED', $reasons, true) => ' Turn on the Gmail Postmaster Tools API in the service account\'s Google Cloud project.',
            $status === 401 => ' Google didn\'t take the service account\'s access token.',
            $status === 403, $status === 404 => " Add the service account ({$this->account->email}) as a user of {$domain} in Postmaster Tools: it reads only the domains it was added to.",
            $status === 429 => ' Google is limiting requests.',
            default => '',
        };
    }

    /**
     * One statistic as [name, date, value], or null when it isn't one of
     * the metrics asked for or has no date.
     *
     * @param  array<mixed>  $stat
     * @param  array<string, array{metric: string, filter?: string}>  $metrics
     * @return ?array{name: string, date: string, value: ?float}
     */
    protected static function stat(array $stat, array $metrics): ?array
    {
        $name = $stat['metric'] ?? null;
        $year = data_get($stat, 'date.year');
        $month = data_get($stat, 'date.month');
        $day = data_get($stat, 'date.day');

        if (! is_string($name) || ! isset($metrics[$name]) || ! is_int($year) || ! is_int($month) || ! is_int($day) || ! checkdate($month, $day, $year)) {
            return null;
        }

        // Google sends a number as floatValue, doubleValue or, for a whole
        // one, intValue as a string.
        $value = data_get($stat, 'value.floatValue') ?? data_get($stat, 'value.doubleValue') ?? data_get($stat, 'value.intValue');
        $value = is_numeric($value) ? (float) $value : null;

        return [
            'name' => $name,
            'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'value' => $value !== null && $value >= 0 && $value <= 1 ? $value : null,
        ];
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    protected static function day(CarbonInterface $day): array
    {
        return ['year' => $day->year, 'month' => $day->month, 'day' => $day->day];
    }

    /**
     * The domain as a path segment Postmaster Tools reads, or a refusal.
     *
     * @throws PostmasterException
     */
    public static function domain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain) !== 1) {
            throw new PostmasterException('The sending domain isn\'t a domain name Postmaster Tools reads.');
        }

        return $domain;
    }
}
