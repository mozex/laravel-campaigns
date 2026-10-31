<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Mozex\Campaigns\CampaignsException;
use Mozex\Campaigns\CampaignsServiceProvider;
use Mozex\Campaigns\Contracts\CampaignDriver;
use Mozex\Campaigns\Sending\Drivers\DriverManager;
use Mozex\Campaigns\Sending\Drivers\FakeDriver;
use Mozex\Campaigns\Sending\Drivers\MailgunDriver;

it('keeps sending off until the app turns it on', function (): void {
    expect(config('campaigns.enabled'))->toBeFalse()
        ->and(config('campaigns.queue.name'))->toBe('campaigns');
});

it('sends through the driver the config names, Mailgun by default, resolved afresh so a switch reaches later callers', function (mixed $value, ?string $class): void {
    config()->set('campaigns.driver', $value);

    if ($class === null) {
        expect(fn () => app(CampaignDriver::class))->toThrow(CampaignsException::class, 'campaigns.driver');

        return;
    }

    $driver = app(CampaignDriver::class);
    config()->set('campaigns.driver', $class === FakeDriver::class ? 'mailgun' : 'fake');

    expect($driver)->toBeInstanceOf($class)
        ->and(app(CampaignDriver::class))->not->toBeInstanceOf($class)
        ->and(app(DriverManager::class)->get(is_string($value) ? $value : ''))->toBe($driver);
})->with([
    'mailgun' => ['mailgun', MailgunDriver::class],
    'the fake' => ['fake', FakeDriver::class],
    'a class name, no longer read' => [FakeDriver::class, null],
    'an unknown name' => ['postmark', null],
    'the reserved name' => ['campaigns', null],
    'not text' => [['mailgun'], null],
]);

it('defaults to the mailgun driver, keeping Mailgun\'s env names', function (): void {
    $defaults = require __DIR__.'/../config/campaigns.php';
    $config = file_get_contents(__DIR__.'/../config/campaigns.php');

    expect($defaults['driver'])->toBe('mailgun')
        ->and($defaults['drivers']['mailgun'])->toHaveKeys(['webhook_signing_key', 'api_key', 'endpoint', 'log_days'])
        ->and($config)->toContain("env('CAMPAIGNS_DRIVER', 'mailgun')")
        ->and($config)->toContain("env('MAILGUN_WEBHOOK_SIGNING_KEY')")
        ->and($config)->toContain("env('CAMPAIGNS_MAILGUN_API_KEY')")
        ->and($config)->toContain("env('CAMPAIGNS_MAILGUN_ENDPOINT')");
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

it('schedules the pump, the reconciler and pruning without overlapping, each lock its own on the configured store, unless told not to', function (): void {
    $scheduled = function (): array {
        app()->forgetInstance(Schedule::class);

        $events = [];

        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match('/campaigns:(pump|reconcile|prune)\b/', (string) $event->command, $match) === 1) {
                $events[$match[1]] = $event;
            }
        }

        return $events;
    };

    config()->set('campaigns.cache_store', 'array');
    $events = $scheduled();

    expect($events)->toHaveKeys(['pump', 'reconcile', 'prune'])
        ->and($events['pump'])
        ->expression->toBe('* * * * *')
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(5)
        ->runInBackground->toBeFalse()
        ->and($events['reconcile'])
        ->expression->toBe('*/15 * * * *')
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(20)
        ->runInBackground->toBeTrue()
        ->and($events['prune'])
        ->expression->toBe('0 0 * * *')
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(120)
        ->runInBackground->toBeTrue();

    foreach ($events as $event) {
        expect($event->mutex)->toBeInstanceOf(CacheEventMutex::class)
            ->and($event->mutex->store)->toBe('array');
    }

    expect($events['pump']->mutex)->not->toBe($events['reconcile']->mutex)
        ->and($events['reconcile']->mutex)->not->toBe($events['prune']->mutex);

    config()->set('campaigns.schedule', false);

    expect($scheduled())->toBe([]);
});
