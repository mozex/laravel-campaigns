<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\Drivers\ReadsEvents;
use Mozex\Campaigns\Contracts\Drivers\ReadsSuppressions;
use Mozex\Campaigns\Contracts\Drivers\ReceivesWebhooks;
use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Jobs\SendMessageJob;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Reports\DomainSummary;
use Mozex\Campaigns\Reports\GmailReading;
use Mozex\Campaigns\Reports\Overview;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Sending\DomainMetrics;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeSendOnlyDriver;
use Mozex\Campaigns\Sending\Drivers\FakeWebhookDriver;
use Mozex\Campaigns\Sending\Usage;

it('reads every list\'s counts, the open campaigns and the placeholders with the app\'s values', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();

    config([
        'app.name' => 'Acme',
        'campaigns.postal_address' => '1 Main Street, Springfield',
        'campaigns.privacy_url' => 'https://acme.example/privacy',
        'campaigns.driver' => 'mailgun',
        'campaigns.drivers.mailgun.api_key' => 'key-123',
        'campaigns.test_addresses' => ['Owner@Acme.example'],
        'campaigns.checks.spam_score' => true,
    ]);
    sendFromExample();

    $readers = CampaignList::factory()->create(['key' => 'readers', 'name' => 'Readers']);
    $members = CampaignList::factory()->dynamic()->create(['key' => 'members', 'name' => 'Members', 'double_opt_in' => true]);

    subscriberOn([$readers, $members], ['last_opened_at' => now()->subDays(10)]);
    subscriberOn([$readers], ['last_clicked_at' => now()->subDays(200)]);
    subscriberOn([$readers], ['first_mailed_at' => now()->subDays(20)]);
    subscriberOn([$readers], ['last_opened_at' => now()->subDays(5), 'opted_out_at' => now()]);
    subscriberOn([$readers], ['first_mailed_at' => now()->subMonths(13)]);
    subscriberOn([$readers], status: SubscriptionStatus::Pending);
    subscriberOn([$readers, $members], status: SubscriptionStatus::Unsubscribed);

    $sending = Campaign::factory()->create(['key' => 'october', 'status' => CampaignStatus::Sending, 'recipient_count' => 4, 'started_at' => now()->subHour()]);
    $scheduled = Campaign::factory()->create(['key' => 'november', 'status' => CampaignStatus::Scheduled, 'recipient_count' => 3, 'scheduled_at' => now()->addDay()]);
    Campaign::factory()->create(['key' => 'draft', 'status' => CampaignStatus::Draft]);
    Campaign::factory()->create(['key' => 'done', 'status' => CampaignStatus::Completed]);

    Http::preventStrayRequests();
    Http::fake([
        // The domain's days on the mailer's host; the account's usage on
        // both regions' hosts.
        'https://api.mailgun.net/v1/analytics/metrics' => fn (ClientRequest $request): PromiseInterface => Http::response(['items' => $request['metrics'] === ['processed_count'] ? [mailgunMetricsDay(now(), ['processed_count' => 120])] : []]),
        'https://api.eu.mailgun.net/v1/analytics/metrics' => Http::response(['items' => [mailgunMetricsDay(now(), ['processed_count' => 3000])]]),
    ]);
    config()->set('campaigns.drivers.mailgun.monthly_included', '50000');

    $overview = CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors();

    $content = structuredContent($overview);

    expect($content['domain']['name'])->toBe('news.example.com')
        ->and($content['domain']['provider']['from'])->toBe(now()->utc()->subDays(DomainSummary::DAYS - 1)->toDateString())
        ->and($content['domain']['provider']['to'])->toBe(now()->utc()->toDateString())
        ->and($content['domain']['provider']['days'])->toHaveCount(DomainSummary::DAYS)
        ->and($content['domain']['provider']['totals'])->toBe(['sent' => 0, 'forwarded' => 0, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0]);

    Http::assertSent(fn (ClientRequest $request): bool => $request['resolution'] === 'day');

    expect($content['usage'])->toBe([
        'read' => true,
        'read_at' => now()->toIso8601String(),
        'period' => 'month',
        'from' => now()->utc()->startOfMonth()->toIso8601String(),
        'to' => now()->utc()->toIso8601String(),
        'sent' => 3120,
        'allowance' => 50000,
        'left' => 46880,
        'past_allowance' => 'billed',
        'per_second' => null,
        'note' => null,
    ]);

    expect($content['app'])->toBe('Acme')
        ->and($content['sending'])->toBe([
            'enabled' => true,
            'driver' => ['name' => 'mailgun', 'abilities' => ['webhooks', 'events', 'suppressions', 'metrics', 'usage']],
            'mailer' => 'campaigns',
            'from_address' => 'news@news.example.com',
            'from_name' => 'Example News',
            'reply_to' => null,
            'domain' => 'news.example.com',
            'test_addresses' => ['owner@acme.example'],
            'spam_score_check' => true,
            'notes' => [],
        ])
        ->and($content['lists'])->toBe([
            [
                'key' => 'members',
                'name' => 'Members',
                'kind' => 'dynamic',
                'is_default' => false,
                'double_opt_in' => true,
                'pending' => 0,
                'subscribed' => 1,
                'unsubscribed' => 1,
                'mailable' => 1,
                'tiers' => ['engaged' => 1, 'active' => 0, 'silent' => 0],
            ],
            [
                'key' => 'readers',
                'name' => 'Readers',
                'kind' => 'static',
                'is_default' => false,
                'double_opt_in' => false,
                'pending' => 1,
                'subscribed' => 5,
                'unsubscribed' => 1,
                'mailable' => 3,
                'tiers' => ['engaged' => 2, 'active' => 1, 'silent' => 2],
            ],
        ])
        ->and($content['lists_total'])->toBe(2)
        ->and(array_column($content['campaigns'], 'key'))->toBe(['november', 'october'])
        ->and($content['campaigns'][1])->toMatchArray([
            'uuid' => $sending->uuid,
            'status' => 'sending',
            'recipient_count' => 4,
            'started_at' => now()->subHour()->toIso8601String(),
        ])
        ->and($content['campaigns'][0]['scheduled_at'])->toBe($scheduled->scheduled_at?->toIso8601String())
        ->and($content['campaigns_total'])->toBe(2)
        ->and(array_column($content['placeholders'], 'value', 'name'))->toMatchArray([
            'first_name' => null,
            'postal_address' => '1 Main Street, Springfield',
            'privacy_url' => 'https://acme.example/privacy',
        ])
        ->and(array_keys(array_filter(array_column($content['placeholders'], 'in_subject', 'name'))))->toBe(['first_name', 'name', 'email']);
})->with('databases');

it('stays under the answer limit with every column at its longest', function (string $character): void {
    // Measured as JSON: a quote takes two characters, a control character six.
    foreach (range(1, Overview::LIMIT + 1) as $n) {
        CampaignList::factory()->create(['key' => str_pad("list-{$n}-", 125, 'k'), 'name' => mb_substr(str_repeat($character, 255), 0, 255)]);
        Campaign::factory()->create([
            'key' => str_pad("campaign-{$n}-", 125, 'k'),
            'name' => mb_substr(str_repeat($character, 255), 0, 255),
            'status' => CampaignStatus::Paused,
            'pause_reason' => str_repeat($character, 5000),
            'scheduled_at' => now(),
            'started_at' => now(),
            'paused_at' => now(),
            'recipient_count' => 1000000,
        ]);
    }

    // The domain's 30 days, every count at its longest.
    sendFromExample();
    config(['campaigns.driver' => 'fake', 'campaigns.domain_daily_caps' => ['news.example.com' => 100_000_000]]);

    foreach (range(0, DomainSummary::DAYS - 1) as $day) {
        fakeDriver()->count(now()->utc()->subDays($day)->toDateString(), array_fill_keys(DomainMetrics::COUNTS, 1_000_000_000));
    }

    // The account's usage, every count at its longest and its notes past
    // the cut.
    fakeDriver()->used(PHP_INT_MAX, PHP_INT_MAX, Usage::DAY, enforced: true, perSecond: 123456.789012345, notes: [str_repeat($character, 5000), str_repeat($character, 5000)]);

    // Gmail's 14 days, every rate with its most digits over the stop line,
    // and Google's checks at their longest for both domains.
    $days = [];

    foreach (range(1, GmailReading::DAYS) as $day) {
        $days[now()->utc()->subDays($day)->toDateString()] = array_fill_keys(array_keys(GmailReading::METRICS), 0.1234567);
    }

    $checks = [
        'domainId' => str_repeat('d', 253),
        'rowData' => array_map(fn (int $n): array => ['requirement' => str_pad("R{$n}_", 40, 'X'), 'status' => ['status' => str_repeat('N', 40)]], range(1, GmailReading::REQUIREMENTS + 5)),
        'oneClickUnsubscribeVerdict' => ['status' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
        'honorUnsubscribeVerdict' => ['status' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
        'deliverabilityStatusVerdict' => ['state' => ['status' => str_repeat('N', 40)], 'reason' => str_repeat('R', 40)],
    ];

    fakeGoogle(postmasterStats($days), ['complianceData' => $checks, 'subdomainComplianceData' => $checks]);

    $content = structuredContent(CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors());

    expect($content['domain']['provider']['days'])->toHaveCount(DomainSummary::DAYS)
        ->and($content['domain']['gmail']['warning'])->toContain('stop line')
        ->and($content['domain']['gmail']['compliance']['subdomain']['requirements'])->toHaveCount(GmailReading::REQUIREMENTS)
        ->and($content['usage']['sent'])->toBe(PHP_INT_MAX)
        ->and(mb_strlen($content['usage']['note']))->toBe(ProviderUsage::NOTE_LENGTH + 3)
        ->and($content['lists'])->toHaveCount(Overview::LIMIT)
        ->and($content['lists_total'])->toBe(Overview::LIMIT + 1)
        ->and($content['campaigns'])->toHaveCount(Overview::LIMIT)
        ->and($content['campaigns_total'])->toBe(Overview::LIMIT + 1)
        ->and(mb_strlen($content['campaigns'][0]['pause_reason']))->toBe(Overview::PAUSE_REASON_LENGTH + 3);
})->with('costly characters');

it('says what is switched off or missing, and what a driver without each ability can\'t do', function (): void {
    app(DriverManager::class)->swap('fake', new FakeSendOnlyDriver);
    config([
        'campaigns.enabled' => false,
        'campaigns.test_addresses' => [],
        'campaigns.mail.from' => ['address' => null, 'name' => null],
        'campaigns.mail.domain' => null,
        'mail.from.address' => null,
    ]);

    $overview = CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk();

    $sending = structuredContent($overview)['sending'];

    // No usage is read, never a count of 0.
    expect(structuredContent($overview)['usage'])->toMatchArray(['read' => false, 'period' => null, 'sent' => null, 'allowance' => null, 'left' => null])
        ->and(structuredContent($overview)['usage']['note'])->toBe('The campaigns driver "fake" reads no usage, so what the account sent against its plan or quota isn\'t known here.');

    expect($sending['enabled'])->toBeFalse()
        ->and($sending['driver'])->toBe(['name' => 'fake', 'abilities' => []])
        ->and($sending['domain'])->toBeNull()
        ->and($sending['notes'])->toHaveCount(5)
        ->and($sending['notes'][0])->toStartWith('No sending domain is configured')
        // One warning for neither webhooks nor logs (decision 53's words).
        ->and($sending['notes'][1])->toStartWith('The campaigns driver "fake" reads nothing back from its service (no webhooks, no logs)')
        ->and($sending['notes'][1])->toContain('the stop-loss sees only what is refused while sending, only a refusal naming a bad mailbox blocks an address')
        ->and($sending['notes'][2])->toStartWith('The campaigns driver "fake" reads no suppression lists')
        ->and($sending['notes'][3])->toStartWith('Sending is switched off')
        ->and($sending['notes'][4])->toStartWith('No test addresses are set');

    // Webhooks without logs: each missing ability on its own.
    app(DriverManager::class)->swap('fake', new FakeWebhookDriver);
    $webhooksOnly = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['sending']['notes'];

    expect($webhooksOnly[1])->toStartWith('The campaigns driver "fake" reads no logs')
        ->and(implode(' ', $webhooksOnly))->not->toContain('reads nothing back');

    // A driver with every ability but counts, and one that can't be built.
    sendFromExample();
    config(['campaigns.test_addresses' => ['owner@example.com'], 'campaigns.enabled' => true]);
    app(DriverManager::class)->swap('fake', new class extends FakeSendOnlyDriver implements ReadsEvents, ReadsSuppressions, ReceivesWebhooks {});
    $noCounts = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());
    config(['campaigns.driver' => 'postmark']);
    $unknown = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());

    expect($noCounts['sending']['driver']['abilities'])->toBe(['webhooks', 'events', 'suppressions'])
        ->and($noCounts['domain']['provider'])->toBeNull()
        ->and($noCounts['sending']['notes'])->toBe(['The campaigns driver "fake" counts nothing by day, so domain.provider is null.'])
        ->and($unknown['sending']['driver'])->toBe(['name' => 'postmark', 'abilities' => []])
        ->and($unknown['domain']['provider'])->toBeNull()
        ->and($unknown['sending']['notes'])->toHaveCount(1)
        ->and($unknown['sending']['notes'][0])->toStartWith('No campaigns driver is called "postmark"')
        ->and($unknown['usage']['read'])->toBeFalse()
        ->and($unknown['usage']['note'])->toStartWith('No campaigns driver is called "postmark"');
});

it('shows the sending domain\'s standing: its last 24 hours, its daily cap and the provider\'s counts', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();
    Queue::fake([SendMessageJob::class]);
    config(['campaigns.domain_daily_caps' => ['news.example.com' => 500]]);
    $campaign = sendingCampaign(1);

    sends($campaign, 98);
    sends($campaign, 2, hardBounce());
    sends($campaign, 1, ['complained_at' => now()]);
    sends($campaign, 3, ['claimed_at' => now()->subHour()]);
    // Older than a day: neither in the last 24 hours nor the cap's.
    sends($campaign, 50, ['claimed_at' => now()->subDays(2)], now()->subDays(2));
    Subscriber::factory()->create(['confirmation_sent_at' => now()->subHour()]);

    fakeDriver()
        ->count(now()->utc()->toDateString(), ['sent' => 104, 'hard_bounces' => 2, 'complaints' => 1])
        ->count(now()->utc()->subDays(DomainSummary::DAYS)->toDateString(), ['sent' => 9999]);

    $domain = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['domain'];

    expect($domain['name'])->toBe('news.example.com')
        ->and($domain['last_24_hours'])->toEqual([
            'sends' => 104,
            'hard_bounces' => 2,
            'complaints' => 1,
            'unsubscribes' => 0,
            'hard_bounce_rate' => 1.923,
            'complaint_rate' => 0.962,
            'unsubscribe_rate' => 0,
        ])
        ->and($domain['daily_cap'])->toBe(['cap' => 500, 'claimed' => 3, 'confirmations' => 1, 'left' => 496])
        ->and($domain['provider']['days'])->toHaveCount(DomainSummary::DAYS)
        ->and($domain['provider']['days'][DomainSummary::DAYS - 1])->toBe(['date' => now()->utc()->toDateString(), 'sent' => 104, 'forwarded' => 0, 'hard_bounces' => 2, 'complaints' => 1, 'unsubscribes' => 0])
        // The day before the 30 isn't counted.
        ->and($domain['provider']['totals']['sent'])->toBe(104);

    fakeDriver()->failReads(CampaignsException::providerUnreadable('Mailgun answered 503 when its logs, suppressions or metrics were read.'));
    $overview = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());

    expect($overview['domain']['provider'])->toBeNull()
        ->and($overview['domain']['last_24_hours']['sends'])->toBe(104)
        ->and(array_slice($overview['sending']['notes'], -1))->toBe(['The provider\'s numbers for news.example.com couldn\'t be read: Mailgun answered 503 when its logs, suppressions or metrics were read.']);

    // With no exception given, as the docs show it, reads fail too, until
    // they're made to work again.
    fakeDriver()->failReads();
    $failing = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());
    fakeDriver()->readsWork();

    expect($failing['domain']['provider'])->toBeNull()
        ->and(structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['domain']['provider'])->not->toBeNull();
})->with('databases');

it('shows Gmail\'s spam rate, authentication and checks for the sending domain', function (): void {
    $this->travelTo(now()->parse('2026-11-23 15:00:00Z'));
    sendFromExample();
    fakeGoogle(postmasterStats(['2026-11-21' => ['spam_rate' => 0.0012, 'dkim_success_rate' => 1.0, 'spf_success_rate' => 1.0, 'dmarc_success_rate' => 0.98]]));

    $gmail = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk()->assertHasNoErrors())['domain']['gmail'];

    expect($gmail)->toMatchArray([
        'read' => true,
        'read_at' => '2026-11-23T15:00:00+00:00',
        'spam_rate' => 0.12,
        'spam_rate_date' => '2026-11-21',
        'warning' => 'Gmail\'s spam rate for news.example.com was 0.12% on 2026-11-21, at or above the 0.1% Google asks bulk senders to stay under.',
        'note' => null,
        'from' => '2026-11-09',
        'to' => '2026-11-22',
    ])
        ->and($gmail['days'])->toHaveCount(GmailReading::DAYS)
        ->and($gmail['days'][12])->toEqual(['date' => '2026-11-21', 'spam_rate' => 0.12, 'dkim_success_rate' => 100, 'spf_success_rate' => 100, 'dmarc_success_rate' => 98])
        ->and($gmail['compliance']['subdomain']['requirements']['USER_REPORTED_SPAM_RATE'])->toBe('NEEDS_WORK')
        ->and($gmail['compliance']['registrable_domain']['deliverability'])->toBe(['status' => 'COMPLIANT', 'reason' => 'USER_FEEDBACK_LOW']);

    Http::assertSentCount(3);
});

it('says Gmail isn\'t read without a key, and shows a requirement-less check as an object', function (): void {
    sendFromExample();
    Http::preventStrayRequests();

    $gmail = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk()->assertHasNoErrors())['domain']['gmail'];

    expect($gmail['read'])->toBeFalse()
        ->and($gmail['note'])->toContain('CAMPAIGNS_POSTMASTER_CREDENTIALS');

    Http::assertNothingSent();

    fakeGoogle(compliance: ['complianceData' => ['domainId' => 'example.com', 'rowData' => []]]);

    expect(structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk()->assertHasNoErrors())['domain']['gmail']['compliance'])
        ->toBe(['registrable_domain' => ['domain' => 'example.com', 'requirements' => [], 'one_click_unsubscribe' => null, 'honor_unsubscribe' => null, 'deliverability' => null], 'subdomain' => null])
        // Kept as a JSON object, as the schema says, though empty.
        ->and(json_encode(GmailReading::for('news.example.com')['compliance']))->toContain('"requirements":{}');
});

it('asks Mailgun nothing for the domain\'s numbers without the key that reads them, and says so', function (): void {
    Http::preventStrayRequests();
    sendFromExample();
    config(['campaigns.driver' => 'mailgun', 'campaigns.drivers.mailgun.api_key' => null]);

    $content = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());

    expect($content['domain']['provider'])->toBeNull()
        ->and($content['domain']['last_24_hours']['sends'])->toBe(0)
        ->and($content['sending']['notes'])->toHaveCount(2)
        ->and($content['sending']['notes'][0])->toStartWith('No test addresses are set')
        ->and($content['sending']['notes'][1])->toContain('CAMPAIGNS_MAILGUN_API_KEY');

    Http::assertNothingSent();
});

it('shows a count the provider doesn\'t keep as null, never 0', function (): void {
    sendFromExample();
    fakeDriver()->count(now()->utc()->toDateString(), ['sent' => 7, 'forwarded' => null, 'unsubscribes' => null]);

    $provider = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['domain']['provider'];

    expect($provider['days'][DomainSummary::DAYS - 1])->toBe(['date' => now()->utc()->toDateString(), 'sent' => 7, 'forwarded' => null, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => null])
        ->and($provider['totals'])->toBe(['sent' => 7, 'forwarded' => 0, 'hard_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0]);
});
