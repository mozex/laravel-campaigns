<?php

declare(strict_types=1);

use Aws\SesV2\SesV2Client;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Mozex\Campaigns\Actions\PumpCampaignsAction;
use Mozex\Campaigns\Actions\ReconcileCampaignsAction;
use Mozex\Campaigns\Enums\MessageStatus;
use Mozex\Campaigns\Enums\Rights;
use Mozex\Campaigns\Facades\Campaigns;
use Mozex\Campaigns\Mcp\Tools\OverviewTool;
use Mozex\Campaigns\Mcp\Tools\UnblockContactTool;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Reports\ProviderUsage;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\Drivers\MailgunDriver;
use Mozex\Campaigns\Support\Docs;

/**
 * Mailgun as the active driver with only its sending mailer: neither the key
 * that reads its logs, lists, counts and usage nor its webhook signing key.
 */
function keylessMailgun(): void
{
    sendFromExample();
    config()->set('campaigns.driver', 'mailgun');
    config()->set('campaigns.drivers.mailgun.api_key', null);
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', null);
}

it('reads Mailgun\'s logs only with the key that reads them, and without it reports nothing and settles an email left sending as unknown', function (): void {
    Http::preventStrayRequests();
    Exceptions::fake();
    $campaign = sendingCampaign(2);
    app(PumpCampaignsAction::class)->execute();
    keylessMailgun();
    [$left, $unclear] = $campaign->messages()->orderBy('position')->get()->all();
    $left->forceFill(['status' => MessageStatus::Sending, 'driver' => 'mailgun', 'sending_at' => now()->subMinutes(20)])->save();
    $unclear->forceFill(['status' => MessageStatus::Unknown, 'driver' => 'mailgun', 'sending_at' => now()->subMinutes(20), 'failure_reason' => 'unclear'])->save();

    $result = app(ReconcileCampaignsAction::class)->execute();

    expect($result->problems)->toBe([])
        ->and($result->settled)->toBe(1)
        ->and($left->fresh()->status)->toBe(MessageStatus::Unknown)
        ->and($unclear->fresh()->status)->toBe(MessageStatus::Unknown);

    $this->artisan('campaigns:reconcile')->assertSuccessful();

    Exceptions::assertNothingReported();
    Http::assertNothingSent();

    // The key set later, both are looked up as any unclear send is.
    config()->set('campaigns.drivers.mailgun.api_key', 'key-reader');
    Http::fake(['api.mailgun.net/v1/analytics/logs' => fn (ClientRequest $request) => Http::response(match ($request['filter']['AND'][0]['attribute']) {
        'user_variables' => mailgunLogs([mailgunLogItem('accepted', [
            'timestamp' => now()->subMinutes(20)->getTimestamp(),
            'id' => 'event-'.$request['filter']['AND'][0]['values'][0]['value'],
            'user-variables' => ['campaign-message' => $request['filter']['AND'][0]['values'][0]['value']],
        ])]),
        default => mailgunLogs([]),
    })]);

    $result = app(ReconcileCampaignsAction::class)->execute();

    expect($result->problems)->toBe([])
        ->and($result->found)->toBe(2)
        ->and($left->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($unclear->fresh()->status)->toBe(MessageStatus::Sent);
});

it('answers a webhook for a driver whose key isn\'t set with a 500 its service retries, and reports nothing', function (): void {
    Exceptions::fake();
    keylessMailgun();

    $this->postJson(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(mailgunEvent('delivered')))->assertStatus(500);
    $this->postJson(route('campaigns.webhooks', 'mailgun'), [])->assertStatus(500);

    Exceptions::assertNothingReported();

    // A key set but not the one that signed it still refuses the request.
    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'another-key');

    $this->postJson(route('campaigns.webhooks', 'mailgun'), mailgunWebhook(mailgunEvent('delivered')))->assertStatus(406);
});

it('lists only the abilities a driver\'s settings turn on, and says in the overview what each missing setting would add', function (): void {
    Http::preventStrayRequests();
    keylessMailgun();

    $content = structuredContent(agent(Rights::Read)->tool(OverviewTool::class)->assertOk());
    $notes = implode("\n", $content['sending']['notes']);

    expect($content['sending']['driver'])->toBe(['name' => 'mailgun', 'abilities' => []])
        ->and($notes)->toContain('CAMPAIGNS_MAILGUN_API_KEY')
        ->and($notes)->toContain('MAILGUN_WEBHOOK_SIGNING_KEY')
        ->and($notes)->toContain('reads nothing back')
        ->and($content['usage']['read'])->toBeFalse()
        ->and($content['usage']['note'])->toContain('CAMPAIGNS_MAILGUN_API_KEY')
        ->and($content['domain']['provider'])->toBeNull();

    Http::assertNothingSent();

    config()->set('campaigns.drivers.mailgun.webhook_signing_key', 'webhook-signing-key');

    expect(DriverManager::abilities(app(DriverManager::class)->active()))->toBe(['webhooks']);

    config()->set('campaigns.drivers.mailgun.api_key', 'key-reader');

    expect(DriverManager::abilities(app(DriverManager::class)->active()))->toBe(['webhooks', 'events', 'suppressions', 'metrics', 'usage']);
});

it('reads the usage as soon as the key is set, never keeping the answer that it isn\'t', function (): void {
    Http::preventStrayRequests();
    keylessMailgun();

    expect(ProviderUsage::read()['read'])->toBeFalse();

    config()->set('campaigns.drivers.mailgun.api_key', 'key-reader');
    Http::fake(['*/v1/analytics/metrics' => Http::response(['items' => [mailgunMetricsDay(now()->utc(), ['processed_count' => 12])]])]);

    expect(ProviderUsage::read())->toMatchArray(['read' => true, 'sent' => 24]);
});

it('refuses the suppressions import and the unblock check without the key, naming it and asking Mailgun nothing', function (): void {
    Http::preventStrayRequests();
    keylessMailgun();
    Subscriber::forEmail('someone@example.org')->forceFill(['blocked_at' => now(), 'blocked_reason' => 'hard_bounce'])->save();

    $this->artisan('campaigns:import-provider-suppressions', ['domain' => 'news.example.com'])
        ->expectsOutputToContain('CAMPAIGNS_MAILGUN_API_KEY')
        ->assertFailed();

    $answer = structuredContent(agent(Rights::Full)->tool(UnblockContactTool::class, ['email' => 'someone@example.org'])->assertOk());

    expect($answer['provider']['checked'])->toBeFalse()
        ->and($answer['provider']['error'])->toContain('CAMPAIGNS_MAILGUN_API_KEY');

    Http::assertNothingSent();
});

it('turns Amazon SES\'s webhooks on with their secret and its daily counts with VDM', function (): void {
    config()->set('campaigns.driver', 'ses');
    config()->set('campaigns.drivers.ses.webhook_secret', null);
    config()->set('campaigns.drivers.ses.vdm', false);
    $drivers = app(DriverManager::class);

    expect(DriverManager::abilities($drivers->active()))->toBe(['suppressions', 'usage'])
        ->and($drivers->readsBack('ses'))->toBeFalse();

    config()->set('campaigns.drivers.ses.webhook_secret', 'webhook-secret');
    config()->set('campaigns.drivers.ses.vdm', true);

    expect(DriverManager::abilities($drivers->active()))->toBe(['webhooks', 'suppressions', 'metrics', 'usage'])
        ->and($drivers->readsBack('ses'))->toBeTrue();
})->skip(fn (): bool => ! class_exists(SesV2Client::class), 'The AWS SDK for PHP isn\'t installed.');

it('asks for a driver\'s abilities only through the driver manager', function (): void {
    $abilities = 'ReceivesWebhooks|ReadsEvents|ReadsSuppressions|ReadsMetrics|ReadsUsage';
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && $file->getFilename() !== 'DriverManager.php' && preg_match('/instanceof\s+\\\\?(?:[\w\\\\]*\\\\)?(?:'.$abilities.')\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = $file->getFilename();
        }
    }

    expect($found)->toBe([]);
});

it('points every note at a page the docs hold', function (): void {
    $pages = [Docs::AGENTS, Docs::CHECKS, Docs::FEEDBACK, Docs::EVENTS, MailgunDriver::PAGE, ...array_filter(array_map(fn (string $name): ?string => app(DriverManager::class)->page($name), app(DriverManager::class)->names()))];

    foreach ($pages as $page) {
        expect(__DIR__.'/../../../docs/'.$page)->toBeFile();
    }

    expect(Docs::url(Docs::AGENTS))->toStartWith(Docs::REPOSITORY.'/blob/')
        ->and(app(DriverManager::class)->page('postmark'))->toBeNull();
});

it('holds a domain\'s bookmark while the logs its mail went through wait for their key, whatever other driver reads logs', function (): void {
    Http::preventStrayRequests();
    $campaign = sendingCampaign(1);
    app(PumpCampaignsAction::class)->execute();
    keylessMailgun();
    Campaigns::extend('other', fn (): FakeDriver => new FakeDriver);
    $campaign->messages()->update(['driver' => 'mailgun']);
    $this->travel(2)->hours();

    $result = app(ReconcileCampaignsAction::class)->execute();

    expect($result->problems)->toBe([])
        ->and($campaign->fresh()->events_read_until)->toBeNull();
});
