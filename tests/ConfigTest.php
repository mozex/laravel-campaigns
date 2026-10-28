<?php

declare(strict_types=1);

use Mozex\Campaigns\CampaignsServiceProvider;

it('keeps sending off until the app turns it on', function (): void {
    expect(config('campaigns.enabled'))->toBeFalse()
        ->and(config('campaigns.queue.name'))->toBe('campaigns');
});

it('fills a published nested group in from the defaults', function (): void {
    config()->set('campaigns', [
        'pace' => ['first_day' => 500],
        'domain_daily_caps' => ['news.example.com' => 3000],
    ]);

    (new CampaignsServiceProvider(app()))->packageRegistered();

    expect(config('campaigns.pace.first_day'))->toBe(500)
        ->and(config('campaigns.pace.per_minute'))->toBe(10)
        ->and(config('campaigns.stop_loss.complaint_rate'))->toBe(0.08)
        ->and(config('campaigns.domain_daily_caps'))->toBe(['news.example.com' => 3000]);
});
