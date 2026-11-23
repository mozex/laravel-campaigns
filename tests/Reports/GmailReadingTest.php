<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Postmaster\Postmaster;
use Mozex\Campaigns\Postmaster\PostmasterException;
use Mozex\Campaigns\Postmaster\ServiceAccount;
use Mozex\Campaigns\Reports\GmailReading;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-23 15:00:00Z'));
});

/**
 * The requests Google was sent, in order, as "METHOD url".
 *
 * @return list<string>
 */
function googleRequests(): array
{
    return Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->values()->all();
}

/**
 * A day of the reading with every rate unknown.
 *
 * @return array<string, ?string>
 */
function unknownDay(string $date): array
{
    return ['date' => $date, 'spam_rate' => null, 'dkim_success_rate' => null, 'spf_success_rate' => null, 'dmarc_success_rate' => null];
}

it('reads the sending domain\'s spam rate, authentication and Google\'s checks as the service account, asking only the read-only scope', function (): void {
    // Each answer keeps the options its request went with.
    $options = [];
    $answer = function (array $body) use (&$options): Closure {
        return function (ClientRequest $request, array $sent) use (&$options, $body) {
            $options[] = $sent;

            return Http::response($body);
        };
    };

    fakeGoogle(
        $answer(postmasterStats([
            '2026-11-11' => ['spam_rate' => 0.0, 'dkim_success_rate' => '1', 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 1.0],
            '2026-11-20' => ['spam_rate' => 0.0074074073, 'dkim_success_rate' => '1', 'spf_success_rate' => 0.99, 'dmarc_success_rate' => 0.9996644],
        ])),
        $answer(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/Google/compliance.json'), true, flags: JSON_THROW_ON_ERROR)),
        $answer(['access_token' => 'test-access-token', 'expires_in' => 3599, 'token_type' => 'Bearer']),
    );

    $reading = GmailReading::for('news.example.com');

    expect($reading)->toMatchArray([
        'read' => true,
        'read_at' => '2026-11-23T15:00:00+00:00',
        'spam_rate' => 0.7407,
        'spam_rate_date' => '2026-11-20',
        'warning' => 'Gmail\'s spam rate for news.example.com was 0.7407% on 2026-11-20, at or above 0.3%, Gmail\'s stop line: Google says a bulk sender must never reach it.',
        'note' => null,
        'from' => '2026-11-09',
        'to' => '2026-11-22',
    ])
        ->and($reading['days'])->toHaveCount(GmailReading::DAYS)
        ->and($reading['days'][0])->toBe(unknownDay('2026-11-09'))
        ->and($reading['days'][2])->toBe(['date' => '2026-11-11', 'spam_rate' => 0.0, 'dkim_success_rate' => 100.0, 'spf_success_rate' => 100.0, 'dmarc_success_rate' => 100.0])
        ->and($reading['days'][11])->toBe(['date' => '2026-11-20', 'spam_rate' => 0.7407, 'dkim_success_rate' => 100.0, 'spf_success_rate' => 99.0, 'dmarc_success_rate' => 99.9664])
        ->and($reading['days'][13])->toBe(unknownDay('2026-11-22'))
        ->and($reading['compliance'])->toEqual([
            'registrable_domain' => [
                'domain' => 'example.com',
                'requirements' => ['SPF_AND_DKIM' => 'COMPLIANT', 'DMARC_ALIGNMENT' => 'COMPLIANT', 'DMARC_POLICY' => 'COMPLIANT', 'ENCRYPTION' => 'COMPLIANT', 'USER_REPORTED_SPAM_RATE' => 'COMPLIANT', 'DNS_RECORDS' => 'COMPLIANT'],
                'one_click_unsubscribe' => ['status' => 'COMPLIANT', 'reason' => null],
                'honor_unsubscribe' => ['status' => 'COMPLIANT', 'reason' => null],
                'deliverability' => ['status' => 'COMPLIANT', 'reason' => 'USER_FEEDBACK_LOW'],
            ],
            'subdomain' => [
                'domain' => 'news.example.com',
                'requirements' => ['SPF_AND_DKIM' => 'COMPLIANT', 'DMARC_ALIGNMENT' => 'COMPLIANT', 'DMARC_POLICY' => 'COMPLIANT', 'ENCRYPTION' => 'COMPLIANT', 'USER_REPORTED_SPAM_RATE' => 'NEEDS_WORK', 'DNS_RECORDS' => 'COMPLIANT'],
                'one_click_unsubscribe' => ['status' => 'COMPLIANT', 'reason' => null],
                'honor_unsubscribe' => ['status' => 'NEEDS_WORK', 'reason' => 'NOT_HONORING'],
                'deliverability' => ['status' => 'NEEDS_WORK', 'reason' => 'SPAM_RATE_HIGH'],
            ],
        ]);

    // One sign-in at Google's own token address (never the key file's
    // token_uri), then the two reads; nothing lists or changes a domain.
    expect(googleRequests())->toBe([
        'POST '.ServiceAccount::TOKEN_URL,
        'POST '.Postmaster::API.'domains/news.example.com/domainStats:query',
        'GET '.Postmaster::API.'domains/news.example.com/complianceStatus',
    ]);

    [$signIn, $stats, $checks] = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();

    expect($stats->data())->toBe([
        'metricDefinitions' => [
            ['name' => 'spam_rate', 'baseMetric' => ['standardMetric' => 'SPAM_RATE']],
            ['name' => 'dkim_success_rate', 'baseMetric' => ['standardMetric' => 'AUTH_SUCCESS_RATE'], 'filter' => 'auth_type = "dkim"'],
            ['name' => 'spf_success_rate', 'baseMetric' => ['standardMetric' => 'AUTH_SUCCESS_RATE'], 'filter' => 'auth_type = "spf"'],
            ['name' => 'dmarc_success_rate', 'baseMetric' => ['standardMetric' => 'AUTH_SUCCESS_RATE'], 'filter' => 'auth_type = "dmarc"'],
        ],
        'timeQuery' => ['dateRanges' => ['dateRanges' => [['start' => ['year' => 2026, 'month' => 11, 'day' => 9], 'end' => ['year' => 2026, 'month' => 11, 'day' => 22]]]]],
        'aggregationGranularity' => 'DAILY',
        'pageSize' => 200,
    ])
        ->and($stats->header('Authorization'))->toBe(['Bearer test-access-token'])
        ->and($checks->header('Authorization'))->toBe(['Bearer test-access-token'])
        ->and($signIn['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');

    // The assertion is a JWT signed with the account's key for the one
    // read-only scope, addressed to Google's token endpoint.
    $decode = fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));
    [$header, $claims, $signature] = explode('.', (string) $signIn['assertion']);

    expect(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(json_decode($decode($claims), true))->toBe([
            'iss' => 'reader@example-project.iam.gserviceaccount.com',
            'scope' => 'https://www.googleapis.com/auth/postmaster.traffic.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => now()->getTimestamp(),
            'exp' => now()->getTimestamp() + 3600,
        ])
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), googleKey()['public'], OPENSSL_ALGO_SHA256))->toBe(1);

    // Every request gives up after 10 seconds and follows no redirect.
    expect($options)->toHaveCount(3);

    foreach ($options as $sent) {
        expect($sent)->toMatchArray(['timeout' => 10, 'connect_timeout' => 10, 'allow_redirects' => false]);
    }
});

it('reads its days under the immutable dates an app may choose', function (): void {
    Date::use(CarbonImmutable::class);

    try {
        fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0004, 'dkim_success_rate' => 1.0]]));
        $reading = GmailReading::for('news.example.com');
    } finally {
        Date::useDefault();
    }

    expect($reading['days'])->toHaveCount(GmailReading::DAYS)
        ->and($reading['days'][12]['date'])->toBe('2026-11-21')
        ->and($reading['spam_rate'])->toBe(0.04)
        ->and($reading['from'])->toBe('2026-11-09');
});

it('reads a kept reading back whole from a store that unserializes no classes', function (): void {
    config([
        'cache.serializable_classes' => false,
        'cache.stores.campaigns' => ['driver' => 'array', 'serialize' => true],
        'campaigns.cache_store' => 'campaigns',
    ]);
    fakeGoogle(compliance: ['complianceData' => ['domainId' => 'example.com', 'rowData' => []]]);

    $first = json_encode(GmailReading::for('news.example.com'));
    $kept = json_encode(GmailReading::for('news.example.com'));

    expect($kept)->toBe($first)
        ->toContain('"requirements":{}');

    Http::assertSentCount(3);
});

it('reads a day with no row, a day of zeros and a day without a spam rate as unknown, and warns only on the latest known day', function (): void {
    fakeGoogle(postmasterStats([
        // A day over Gmail's stop line, older than the latest known day.
        '2026-11-12' => ['spam_rate' => 0.006, 'dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 1.0],
        '2026-11-18' => ['spam_rate' => 0.0002, 'dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 1.0],
        // Rates that can't be rates, and a metric that wasn't asked for.
        '2026-11-19' => ['spam_rate' => 1.5, 'dkim_success_rate' => -0.1, 'spf_success_rate' => 1.0, 'delivery_error_rate' => 0.5],
        // Every rate 0, DKIM and SPF included: a day Gmail filled with zeros.
        '2026-11-20' => ['spam_rate' => 0.0, 'dkim_success_rate' => 0.0, 'spf_success_rate' => '0', 'dmarc_success_rate' => 0.0],
        // Rows, but no spam rate.
        '2026-11-21' => ['dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0],
    ]));

    $reading = GmailReading::for('news.example.com');

    expect($reading['spam_rate'])->toBe(0.02)
        ->and($reading['spam_rate_date'])->toBe('2026-11-18')
        ->and($reading['warning'])->toBeNull()
        ->and($reading['note'])->toBeNull()
        ->and($reading['days'][3])->toBe(['date' => '2026-11-12', 'spam_rate' => 0.6, 'dkim_success_rate' => 100.0, 'spf_success_rate' => 100.0, 'dmarc_success_rate' => 100.0])
        ->and($reading['days'][10])->toBe(['date' => '2026-11-19', 'spam_rate' => null, 'dkim_success_rate' => null, 'spf_success_rate' => 100.0, 'dmarc_success_rate' => null])
        ->and($reading['days'][11])->toBe(unknownDay('2026-11-20'))
        ->and($reading['days'][12])->toBe(['date' => '2026-11-21', 'spam_rate' => null, 'dkim_success_rate' => 100.0, 'spf_success_rate' => 100.0, 'dmarc_success_rate' => null])
        ->and($reading['days'][13])->toBe(unknownDay('2026-11-22'));
});

it('warns at Google\'s lines, judged on the rate as shown', function (float $fraction, float $shown, ?string $warning): void {
    fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => $fraction, 'dkim_success_rate' => 1.0]]));

    $reading = GmailReading::for('news.example.com');

    expect($reading['spam_rate'])->toBe($shown)
        ->and($reading['warning'])->toBe($warning === null ? null : "Gmail's spam rate for news.example.com was {$shown}% on 2026-11-21, {$warning}");
})->with([
    'just under 0.1%' => [0.000999, 0.0999, null],
    'at 0.1%' => [0.001, 0.1, 'at or above the 0.1% Google asks bulk senders to stay under.'],
    'under 0.1% until rounded as shown' => [0.00099999, 0.1, 'at or above the 0.1% Google asks bulk senders to stay under.'],
    'just under 0.3%' => [0.002999, 0.2999, 'at or above the 0.1% Google asks bulk senders to stay under.'],
    'at 0.3%' => [0.003, 0.3, 'at or above 0.3%, Gmail\'s stop line: Google says a bulk sender must never reach it.'],
]);

it('shows only Google\'s short codes and a domain name from its checks, at most 12 requirements', function (): void {
    fakeGoogle(compliance: ['complianceData' => [
        'domainId' => '"Example" <script>',
        'rowData' => [
            ['requirement' => 'Contact owner@example.com', 'status' => ['status' => 'COMPLIANT']],
            ['requirement' => "ENCRYPTION\n", 'status' => ['status' => 'COMPLIANT']],
            ['requirement' => 'SPF_AND_DKIM', 'status' => ['status' => str_repeat('N', 41)]],
            ...array_map(fn (int $n): array => ['requirement' => "REQUIREMENT_{$n}", 'status' => ['status' => 'COMPLIANT']], range(1, 20)),
        ],
        'oneClickUnsubscribeVerdict' => ['status' => ['status' => 'NEEDS_WORK'], 'reason' => 'see https://example.com'],
        'honorUnsubscribeVerdict' => 'COMPLIANT',
    ], 'subdomainComplianceData' => ['domainId' => "news.example.com\n"]]);

    $checks = GmailReading::for('news.example.com')['compliance'];

    expect($checks['registrable_domain']['domain'])->toBeNull()
        ->and($checks['registrable_domain']['requirements'])->toHaveCount(GmailReading::REQUIREMENTS)
        ->and(array_slice($checks['registrable_domain']['requirements'], 0, 2))->toBe(['SPF_AND_DKIM' => null, 'REQUIREMENT_1' => 'COMPLIANT'])
        ->and($checks['registrable_domain']['one_click_unsubscribe'])->toBe(['status' => 'NEEDS_WORK', 'reason' => null])
        ->and($checks['registrable_domain']['honor_unsubscribe'])->toBeNull()
        ->and($checks['registrable_domain']['deliverability'])->toBeNull()
        ->and($checks['subdomain']['domain'])->toBeNull()
        ->and(json_encode(GmailReading::for('news.example.com')))->not->toContain('owner@example.com');
});

it('says the rate is unknown, never clean, when Gmail reported no day', function (): void {
    fakeGoogle(Http::response('{}'));

    $reading = GmailReading::for('news.example.com');

    expect($reading)->toMatchArray([
        'read' => true,
        'spam_rate' => null,
        'spam_rate_date' => null,
        'warning' => null,
        'note' => 'Gmail reported no spam rate for news.example.com in the 14 days to 2026-11-22: it reports a day only when the domain sent it enough mail, so the rate is unknown, never clean.',
    ])
        ->and(array_column($reading['days'], 'spam_rate'))->toBe(array_fill(0, GmailReading::DAYS, null));
});

it('asks Google nothing without a key, and says so', function (?string $credentials): void {
    Http::preventStrayRequests();
    config(['campaigns.postmaster.credentials' => $credentials]);

    expect(GmailReading::for('news.example.com'))->toBe([
        'read' => false,
        'read_at' => null,
        'spam_rate' => null,
        'spam_rate_date' => null,
        'warning' => null,
        'note' => 'Gmail\'s spam rate isn\'t read: campaigns.postmaster.credentials (CAMPAIGNS_POSTMASTER_CREDENTIALS) holds no Google service account key.',
        'from' => null,
        'to' => null,
        'days' => [],
        'compliance' => null,
    ]);

    Http::assertNothingSent();
})->with(['unset' => [null], 'blank' => ['  ']]);

it('answers a failed read as not read, with its status, and tries again after 5 minutes', function (Closure $fake, string $status, int $sent): void {
    // Counted as they leave, since a request that never reached Google
    // isn't recorded.
    $attempts = 0;
    Event::listen(RequestSending::class, function () use (&$attempts): void {
        $attempts++;
    });
    $fake();

    $reading = GmailReading::for('news.example.com');

    expect($reading)->toMatchArray([
        'read' => false,
        'spam_rate' => null,
        'warning' => null,
        'note' => "Gmail's spam rate for news.example.com couldn't be read: {$status} It is read again after 5 minutes.",
        'days' => [],
        'compliance' => null,
    ])
        ->and($attempts)->toBe($sent);

    $this->travel(4)->minutes();

    expect(GmailReading::for('news.example.com'))->toBe($reading)
        ->and($attempts)->toBe($sent);

    $this->travel(2)->minutes();
    GmailReading::for('news.example.com');

    expect($attempts)->toBe($sent * 2);
})->with([
    'a refused sign-in' => [
        fn () => fakeGoogle(token: Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)),
        'Google refused the service account\'s sign-in (HTTP 400, invalid_grant). The key may have been deleted or disabled, or this server\'s clock may be wrong.',
        1,
    ],
    'a sign-in answer without a token' => [
        fn () => fakeGoogle(token: ['token_type' => 'Bearer']),
        'Google refused the service account\'s sign-in (HTTP 200).',
        1,
    ],
    'a sign-in that can\'t be reached' => [
        fn () => fakeGoogle(token: fn () => throw new ConnectionException('cURL error 6: Could not resolve host')),
        'Google\'s sign-in couldn\'t be reached (ConnectionException).',
        1,
    ],
    'a domain the account isn\'t a user of' => [
        fn () => fakeGoogle(Http::response(['error' => ['code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED']], 403)),
        'Postmaster Tools answered HTTP 403 (PERMISSION_DENIED). Add the service account (reader@example-project.iam.gserviceaccount.com) as a user of news.example.com in Postmaster Tools: it reads only the domains it was added to.',
        2,
    ],
    'the API switched off in the key\'s project' => [
        fn () => fakeGoogle(Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED']]]], 403)),
        'Postmaster Tools answered HTTP 403 (PERMISSION_DENIED, SERVICE_DISABLED). Turn on the Gmail Postmaster Tools API in the service account\'s Google Cloud project.',
        2,
    ],
    'too many requests' => [
        fn () => fakeGoogle(Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)),
        'Postmaster Tools answered HTTP 429 (RESOURCE_EXHAUSTED). Google is limiting requests.',
        2,
    ],
    'a timeout' => [
        fn () => fakeGoogle(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')),
        'Postmaster Tools couldn\'t be reached (ConnectionException).',
        2,
    ],
    'an answer that can\'t be read' => [
        fn () => fakeGoogle(Http::response('<html>Service Unavailable</html>')),
        'Postmaster Tools\' answer (HTTP 200) couldn\'t be read.',
        2,
    ],
    'statistics past the page limit' => [
        fn () => fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0]], next: 'more')),
        'Google\'s statistics ran past 5 pages.',
        1 + Postmaster::MAX_PAGES,
    ],
    'the checks failing after the statistics' => [
        fn () => fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0]]), Http::response('', 500)),
        'Postmaster Tools answered HTTP 500.',
        3,
    ],
]);

it('reads Google\'s HTML error pages as failures when the app makes JSON decoding throw', function (?PromiseInterface $token, ?PromiseInterface $stats, string $status): void {
    if (! property_exists(ClientResponse::class, 'defaultJsonDecodingFlags')) {
        $this->markTestSkipped('This Laravel has no default JSON decoding flags for the HTTP client.');
    }

    ClientResponse::$defaultJsonDecodingFlags = JSON_THROW_ON_ERROR;

    try {
        fakeGoogle($stats ?? [], token: $token);
        $note = GmailReading::for('news.example.com')['note'];
    } finally {
        ClientResponse::$defaultJsonDecodingFlags = 0;
    }

    expect($note)->toBe("Gmail's spam rate for news.example.com couldn't be read: {$status} It is read again after 5 minutes.");
})->with([
    'at the sign-in' => [fn (): PromiseInterface => Http::response('<html>Bad Gateway</html>', 502), null, 'Google refused the service account\'s sign-in (HTTP 502).'],
    'at a read' => [null, fn (): PromiseInterface => Http::response('<html>Service Unavailable</html>', 503), 'Postmaster Tools answered HTTP 503.'],
]);

it('keeps a reading an hour in the campaigns cache store', function (): void {
    config(['cache.stores.campaigns' => ['driver' => 'array'], 'campaigns.cache_store' => 'campaigns']);
    fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0004, 'dkim_success_rate' => 1.0]]));

    $reading = GmailReading::for('news.example.com');
    $this->travel(59)->minutes();

    expect(GmailReading::for('News.Example.com'))->toBe($reading)
        ->and(googleRequests())->toHaveCount(3)
        ->and(Cache::store('campaigns')->get('campaigns:gmail-reading:'.sha1('news.example.com')))->toBe($reading)
        ->and(Cache::store()->get('campaigns:gmail-reading:'.sha1('news.example.com')))->toBeNull();

    $this->travel(2)->minutes();

    expect(GmailReading::for('news.example.com')['read_at'])->toBe(now()->toIso8601String())
        ->and(googleRequests())->toHaveCount(6);
});

it('refuses a key that isn\'t a service account\'s without quoting it or asking Google', function (Closure $credentials, string $status): void {
    Http::preventStrayRequests();
    config(['campaigns.postmaster.credentials' => $credentials()]);

    $reading = GmailReading::for('news.example.com');

    expect($reading['read'])->toBeFalse()
        ->and($reading['note'])->toBe("Gmail's spam rate for news.example.com couldn't be read: {$status} It is read again after 5 minutes.")
        ->and($reading['note'])->not->toContain('BEGIN');

    Http::assertNothingSent();
})->with([
    'not base64' => [fn (): string => '%%% not base64 %%%', 'campaigns.postmaster.credentials isn\'t base64: base64-encode the service account\'s JSON key file.'],
    'not JSON' => [fn (): string => base64_encode('"type": "service_account"'), 'campaigns.postmaster.credentials doesn\'t hold JSON: base64-encode the service account\'s whole JSON key file.'],
    'no private key' => [fn (): string => googleCredentials(['private_key' => null]), 'campaigns.postmaster.credentials isn\'t a service account key: its JSON needs client_email and private_key.'],
    'no account address' => [fn (): string => googleCredentials(['client_email' => 'reader']), 'campaigns.postmaster.credentials isn\'t a service account key: its JSON needs client_email and private_key.'],
    'a private key that isn\'t one' => [fn (): string => googleCredentials(['private_key' => "-----BEGIN PRIVATE KEY-----\nbroken\n-----END PRIVATE KEY-----\n"]), 'The private_key in campaigns.postmaster.credentials isn\'t a readable RSA private key.'],
]);

it('takes the key file\'s JSON as well as its base64', function (): void {
    fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0, 'dkim_success_rate' => 1.0]]));
    config(['campaigns.postmaster.credentials' => "\n".base64_decode(googleCredentials())."\n"]);

    expect(GmailReading::for('news.example.com')['read'])->toBeTrue();
});

it('reads only a domain name', function (): void {
    fakeGoogle();

    expect(GmailReading::for('news.example.com/../domains')['note'])->toBe('Gmail\'s spam rate for news.example.com/../domains couldn\'t be read: The sending domain isn\'t a domain name Postmaster Tools reads. It is read again after 5 minutes.');

    Http::assertNothingSent();
});

it('never lets the key, the signed assertion or the access token reach a log, a report, the cache, a dump or an answer', function (Closure $fake): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });
    Exceptions::fake();
    // Traces keep their arguments, as in development, and the setting is
    // put back for the tests after this one.
    $ignoreArgs = (string) ini_get('zend.exception_ignore_args');
    $this->beforeApplicationDestroyed(fn () => ini_set('zend.exception_ignore_args', $ignoreArgs));
    ini_set('zend.exception_ignore_args', '0');
    sendFromExample();
    $fake();

    $answer = json_encode(structuredContent(CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors()), JSON_THROW_ON_ERROR);

    $signIn = Http::recorded()->first()[0] ?? null;
    $assertion = $signIn instanceof ClientRequest ? (string) $signIn['assertion'] : '';
    $credentials = (string) config('campaigns.postmaster.credentials');
    $account = ServiceAccount::fromCredentials($credentials);

    // What a failure carries: an exception thrown with the credentials as
    // its argument, and one from a read that can't reach Google.
    $failures = [];

    try {
        ServiceAccount::fromCredentials($credentials.'%');
    } catch (PostmasterException $exception) {
        $failures[] = $exception;
    }

    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    Http::fake([Postmaster::API.'*' => fn () => throw new ConnectionException('cURL error 7: test-access-token')]);

    $postmaster = new Postmaster($account);

    try {
        $postmaster->complianceStatus('test-access-token', 'news.example.com');
    } catch (PostmasterException $exception) {
        $failures[] = $exception;
    }

    // A dump through __debugInfo, and the objects' real properties, which
    // Laravel's dump() prints.
    ob_start();
    var_dump($account, $postmaster);
    $dump = ob_get_clean().print_r($account, true).print_r($postmaster, true).print_r((array) $account, true).print_r((array) $postmaster, true);

    // An exception's message and the package's own frames of its trace,
    // with their arguments; the frames above them are the test's, whose
    // closures print the whole app, config included.
    $carried = fn (Throwable $exception): string => $exception->getMessage().print_r(array_filter(
        $exception->getTrace(),
        fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Mozex\\Campaigns\\'),
    ), true);

    $everything = implode("\n", [
        $answer,
        $dump,
        serialize(Cache::store()->get('campaigns:gmail-reading:'.sha1('news.example.com'))),
        ...$logged,
        ...array_map($carried, Exceptions::reported()),
        ...array_map($carried, $failures),
    ]);

    expect($failures)->toHaveCount(2)
        ->and(array_map(fn (PostmasterException $exception): ?Throwable => $exception->getPrevious(), $failures))->toBe([null, null])
        ->and(array_column($failures[0]->getTrace(), 'function'))->toContain('fromCredentials')
        ->and($everything)->toContain('news.example.com')
        ->not->toContain($credentials)
        ->not->toContain(mb_substr(explode("\n", googleKey()['private'])[5], 0, 40))
        ->not->toContain('test-access-token');

    if ($assertion !== '') {
        expect($everything)->not->toContain($assertion)
            ->not->toContain(explode('.', $assertion)[2]);
    }
})->with([
    'a reading' => [fn () => fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0, 'dkim_success_rate' => 1.0]]))],
    'a sign-in refused with the assertion quoted back' => [fn () => fakeGoogle(token: fn (ClientRequest $request) => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad assertion '.$request['assertion']], 400))],
    'a read refused with the token quoted back' => [fn () => fakeGoogle(Http::response(['error' => ['code' => 401, 'message' => 'Token test-access-token expired', 'status' => 'UNAUTHENTICATED']], 401))],
    'a read that can\'t reach Google, the token in the error' => [fn () => fakeGoogle(fn () => throw new ConnectionException('cURL error 28: Bearer test-access-token'))],
]);
