<?php

declare(strict_types=1);

use Mozex\Campaigns\Enums\CampaignStatus;
use Mozex\Campaigns\Enums\SubscriptionStatus;
use Mozex\Campaigns\Mcp\CampaignsServer;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Models\ApiToken;
use Mozex\Campaigns\Models\Campaign;
use Mozex\Campaigns\Models\CampaignList;
use Mozex\Campaigns\Reports\Overview;

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

    $overview = CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors();

    $content = structuredContent($overview);

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

it('stays under the answer limit with every column at its longest', function (): void {
    foreach (range(1, Overview::LIMIT + 1) as $n) {
        CampaignList::factory()->create(['key' => str_pad("list-{$n}-", 125, 'k'), 'name' => str_repeat('N', 255)]);
        Campaign::factory()->create([
            'key' => str_pad("campaign-{$n}-", 125, 'k'),
            'name' => str_repeat('N', 255),
            'status' => CampaignStatus::Paused,
            'pause_reason' => str_repeat('r', 5000),
            'scheduled_at' => now(),
            'started_at' => now(),
            'paused_at' => now(),
            'recipient_count' => 1000000,
        ]);
    }

    $content = structuredContent(CampaignsServer::actingAs(ApiToken::factory()->readOnly()->create(), 'campaigns')
        ->tool(OverviewTool::class)
        ->assertOk()
        ->assertHasNoErrors());

    expect($content['lists'])->toHaveCount(Overview::LIMIT)
        ->and($content['lists_total'])->toBe(Overview::LIMIT + 1)
        ->and($content['campaigns'])->toHaveCount(Overview::LIMIT)
        ->and($content['campaigns_total'])->toBe(Overview::LIMIT + 1)
        ->and(mb_strlen($content['campaigns'][0]['pause_reason']))->toBe(Overview::PAUSE_REASON_LENGTH + 3);
});

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
