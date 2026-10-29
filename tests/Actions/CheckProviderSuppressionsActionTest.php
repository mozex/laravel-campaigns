<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Mozex\Campaigns\Actions\CheckProviderSuppressionsAction;
use Mozex\Campaigns\Actions\UnblockAction;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\BlockReason;
use Mozex\Campaigns\Enums\SuppressionType;
use Mozex\Campaigns\Models\Subscriber;
use Mozex\Campaigns\Sending\MailgunProvider;
use Mozex\Campaigns\Support\ConsentContext;

it('tells whether Mailgun still holds an unblocked address on the marketing domain\'s lists', function (): void {
    sendFromExample();
    config()->set('campaigns.mailgun.api_key', 'key-reader');
    app()->instance(CampaignProvider::class, app(MailgunProvider::class));
    Http::preventStrayRequests();
    Http::fake([
        'api.mailgun.net/v3/domains/news.example.com' => Http::response(['domain' => ['name' => 'news.example.com']]),
        'api.mailgun.net/v3/news.example.com/bounces/person%40example.org' => Http::response(['address' => 'person@example.org', 'code' => '550', 'error' => 'No such mailbox', 'created_at' => 'Thu, 11 Dec 2025 01:49:40 UTC']),
        '*' => Http::response(['message' => 'Address not found'], 404),
    ]);

    $subscriber = Subscriber::factory()->create(['email' => 'person@example.org', 'blocked_at' => now(), 'blocked_reason' => BlockReason::HardBounce]);
    app(UnblockAction::class)->execute($subscriber, new ConsentContext(source: 'agent'));

    $held = app(CheckProviderSuppressionsAction::class)->execute(' Person@Example.org ');

    expect($held)->toHaveCount(1)
        ->and($held[0]->type)->toBe(SuppressionType::Bounce)
        ->and(app(CheckProviderSuppressionsAction::class)->execute('someone@example.org'))->toBe([])
        ->and(fn (): array => app(CheckProviderSuppressionsAction::class)->execute('not an address'))->toThrow(CampaignsException::class);

    Http::assertSentCount(8);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.mailgun.net/v3/news.example.com/complaints/someone%40example.org');
});
