<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mozex\Campaigns\CampaignsException;
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
use Mozex\Campaigns\Reports\Overview;
use Mozex\Campaigns\Sending\DomainMetrics;

it('reads every list\'s counts, the open campaigns and the placeholders with the app\'s values', function (string $driver): void {
    useDatabase($driver);
    $this->freezeSecond();

    config([
        'app.name' => 'Acme',
        'campaigns.postal_address' => '1 Main Street, Springfield',
        'campaigns.privacy_url' => 'https://acme.example/privacy',
        'campaigns.mailgun.api_key' => 'key-123',
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
    Http::fake(['https://api.mailgun.net/v1/analytics/metrics' => Http::response(['items' => []])]);

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

    expect($content['app'])->toBe('Acme')
        ->and($content['sending'])->toBe([
            'enabled' => true,
            'provider' => 'mailgun',
            'mailer' => 'campaigns',
            'from_address' => 'news@news.example.com',
            'from_name' => 'Example News',
            'reply_to' => null,
            'domain' => 'news.example.com',
            'provider_logs_readable' => true,
            'test_addresses' => ['owner@acme.example'],
            'spam_score_check' => true,
            'notes' => [],
        ])
        ->and($content['lists'])->toBe([
            [
                'key' => 'members',
                'name' => 'Members',
                'kind' => 'dynamic',
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
    config(['campaigns.provider' => 'fake', 'campaigns.domain_daily_caps' => ['news.example.com' => 100_000_000]]);

    foreach (range(0, DomainSummary::DAYS - 1) as $day) {
        fakeProvider()->count(now()->utc()->subDays($day)->toDateString(), array_fill_keys(DomainMetrics::COUNTS, 1_000_000_000));
    }

    $content = structuredContent(CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors());

    expect($content['domain']['provider']['days'])->toHaveCount(DomainSummary::DAYS)
        ->and($content['lists'])->toHaveCount(Overview::LIMIT)
        ->and($content['lists_total'])->toBe(Overview::LIMIT + 1)
        ->and($content['campaigns'])->toHaveCount(Overview::LIMIT)
        ->and($content['campaigns_total'])->toBe(Overview::LIMIT + 1)
        ->and(mb_strlen($content['campaigns'][0]['pause_reason']))->toBe(Overview::PAUSE_REASON_LENGTH + 3);
})->with('costly characters');

it('says what is switched off or missing', function (): void {
    config([
        'campaigns.enabled' => false,
        'campaigns.provider' => 'mailgun',
        'campaigns.mailgun.api_key' => null,
        'campaigns.test_addresses' => [],
        'campaigns.mail.from' => ['address' => null, 'name' => null],
        'campaigns.mail.domain' => null,
        'mail.from.address' => null,
    ]);

    $overview = CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk();

    $sending = structuredContent($overview)['sending'];

    expect($sending['enabled'])->toBeFalse()
        ->and($sending['domain'])->toBeNull()
        ->and($sending['notes'])->toHaveCount(4)
        ->and($sending['notes'][0])->toStartWith('No sending domain is configured')
        ->and($sending['notes'][1])->toStartWith('Sending is switched off')
        ->and($sending['notes'][2])->toStartWith('campaigns.mailgun.api_key isn\'t set')
        ->and($sending['notes'][3])->toStartWith('No test addresses are set');
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

    fakeProvider()
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

    fakeProvider()->failReads(CampaignsException::providerUnreadable('Mailgun answered 503 when its logs, suppressions or metrics were read.'));
    $overview = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());

    expect($overview['domain']['provider'])->toBeNull()
        ->and($overview['domain']['last_24_hours']['sends'])->toBe(104)
        ->and(array_slice($overview['sending']['notes'], -1))->toBe(['The provider\'s numbers for news.example.com couldn\'t be read: Mailgun answered 503 when its logs, suppressions or metrics were read.']);

    // With no exception given, as the docs show it, reads fail too, until
    // they're made to work again.
    fakeProvider()->failReads();
    $failing = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());
    fakeProvider()->readsWork();

    expect($failing['domain']['provider'])->toBeNull()
        ->and(structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk())['domain']['provider'])->not->toBeNull();
})->with('databases');

it('asks Mailgun nothing for the domain\'s numbers without the key that reads them', function (): void {
    Http::preventStrayRequests();
    sendFromExample();
    config(['campaigns.provider' => 'mailgun', 'campaigns.mailgun.api_key' => null]);

    $content = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());

    expect($content['domain']['provider'])->toBeNull()
        ->and($content['domain']['last_24_hours']['sends'])->toBe(0)
        ->and($content['sending']['notes'])->toHaveCount(2)
        ->and($content['sending']['notes'][0])->toStartWith('campaigns.mailgun.api_key isn\'t set')
        ->and($content['sending']['notes'][1])->toStartWith('No test addresses are set');

    Http::assertNothingSent();
});
