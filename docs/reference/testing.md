---
title: Testing Your App
weight: 4
---

## The fake provider

Set the provider to `fake` in your test environment, in `phpunit.xml`:

```xml
<env name="CAMPAIGNS_PROVIDER" value="fake"/>
```

It keeps every message it's given and sends nothing. Resolve it from the container to read and script it:

```php
use Mozex\Campaigns\Contracts\CampaignProvider;
use Mozex\Campaigns\Enums\SendFailure;
use Mozex\Campaigns\Sending\FakeProvider;
use Mozex\Campaigns\Sending\SendFailed;

$provider = app(CampaignProvider::class);   // a FakeProvider

$provider->sent();                          // every OutgoingMessage sent: to, subject, html, text...
$provider->failNext(new SendFailed(SendFailure::NotAccepted, 'Rate limited', 429));
$provider->count('2026-01-15', ['sent' => 1200, 'hard_bounces' => 3]);  // the domain's counts that day
$provider->failReads();                     // every read of logs or suppression lists throws
$provider->keepLogsFor(30);
```

`log()` and `suppress()` fill its logs and suppression lists, for code that reconciles or imports them.

## Queues and time

Sending runs through queued jobs. Fake only the send job, so a campaign you start still freezes its audience:

```php
Queue::fake([\Mozex\Campaigns\Jobs\SendMessageJob::class]);
```

The pace, the sending hours and the stop-loss read the clock through `now()`, so `$this->travelTo()` moves them. Travel to UTC instants: a time given in another zone can land on a different day than you meant.

## Hosts

The pre-send checks and image fetches resolve host names before connecting. Bind the static resolver so a test never makes a real DNS lookup:

```php
use Mozex\Campaigns\Fetching\HostResolver;
use Mozex\Campaigns\Fetching\StaticHostResolver;

$this->app->instance(HostResolver::class, new StaticHostResolver(['example.com' => ['93.184.215.14']]));
```

## Factories

Every model has a factory in `Mozex\Campaigns\Database\Factories`, autoloaded with the package, so your tests can make subscribers, lists and campaigns directly: `Subscriber::factory()->create()`.

## Consent in tests

Records your tests create under a list source sync onto their dynamic list as they would in production, as made by staff unless you wrap them in `withCampaignConsent()`. Listeners on the package's events are run after the commit and their failures reported, not thrown, so assert after the action rather than inside a listener.
