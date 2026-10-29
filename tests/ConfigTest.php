<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Mozex\Campaigns\Campaigns;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\CampaignsServiceProvider;
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Sending\FakeProvider;
use Mozex\Campaigns\Sending\MailgunProvider;

it('keeps sending off until the app turns it on', function (): void {
    expect(config('campaigns.enabled'))->toBeFalse()
        ->and(config('campaigns.queue.name'))->toBe('campaigns');
});

it('picks the provider the config names', function (mixed $value, ?string $class): void {
    config()->set('campaigns.provider', $value);
    app()->forgetInstance(CampaignProvider::class);

    if ($class === null) {
        expect(fn () => app(CampaignProvider::class))->toThrow(CampaignsException::class, 'campaigns.provider');

        return;
    }

    expect(app(CampaignProvider::class))->toBeInstanceOf($class)
        ->and(app(CampaignProvider::class))->toBe(app(CampaignProvider::class));
})->with([
    'the default' => ['mailgun', MailgunProvider::class],
    'the fake' => ['fake', FakeProvider::class],
    'an app\'s own' => [FakeProvider::class, FakeProvider::class],
    'something else' => [Campaigns::class, null],
    'not text' => [['mailgun'], null],
]);

it('bounds how long a campaigns Mailgun mailer may wait, unless the app said', function (): void {
    config()->set('mail.mailers.campaigns', ['transport' => 'mailgun', 'scheme' => 'api', 'secret' => 'key-test', 'domain' => 'news.example.com']);
    config()->set('mail.mailers.slow', ['transport' => 'mailgun', 'client' => ['timeout' => 90]]);
    config()->set('mail.mailers.smtp', ['transport' => 'smtp']);

    $provider = new CampaignsServiceProvider(app());
    $provider->packageRegistered();
    $bounded = config('mail.mailers.campaigns.client');

    config()->set('campaigns.mail.mailer', 'slow');
    $provider->packageRegistered();
    config()->set('campaigns.mail.mailer', 'smtp');
    $provider->packageRegistered();

    expect($bounded)->toBe(['timeout' => 10, 'max_duration' => 30])
        ->and(config('mail.mailers.slow.client'))->toBe(['timeout' => 90])
        ->and(config('mail.mailers.smtp'))->toBe(['transport' => 'smtp']);
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

it('schedules the pump every minute without overlapping, its lock on the configured store, unless told not to', function (): void {
    $pumps = function (): array {
        app()->forgetInstance(Schedule::class);

        return array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'campaigns:pump'),
        ));
    };

    config()->set('campaigns.cache_store', 'array');
    [$pump] = $pumps();

    expect($pump)
        ->expression->toBe('* * * * *')
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(5)
        ->and($pump->mutex)->toBeInstanceOf(CacheEventMutex::class)
        ->and($pump->mutex->store)->toBe('array');

    config()->set('campaigns.schedule', false);

    expect($pumps())->toBe([]);
});
